package main

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strconv"
	"sync"
	"testing"
	"time"
)

func TestBrokerBatchesStreamsAndDispatchesEvents(t *testing.T) {
	auth := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = json.NewEncoder(w).Encode(authorizedIdentity{ClientID: r.URL.Query().Get("client_id"), ClientUUID: r.URL.Query().Get("client_uuid"), Authenticated: true})
	}))
	defer auth.Close()
	var mu sync.Mutex
	batchSizes := make([]int, 0, 1)
	snapshot := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var request snapshotRequest
		if err := json.NewDecoder(r.Body).Decode(&request); err != nil {
			t.Fatalf("decode snapshot request: %v", err)
		}
		mu.Lock()
		batchSizes = append(batchSizes, len(request.Streams))
		mu.Unlock()
		response := snapshotResponse{Streams: map[string][]streamEvent{}}
		for _, stream := range request.Streams {
			response.Streams[stream.ConnectionID] = []streamEvent{{
				ID: "7", Type: "update-policy", Data: json.RawMessage(`{"client_id":"` + stream.ClientID + `","policy_revision":7}`),
			}}
		}
		w.Header().Set("Content-Type", "application/json")
		if err := json.NewEncoder(w).Encode(response); err != nil {
			t.Fatalf("encode snapshot response: %v", err)
		}
	}))
	defer snapshot.Close()

	broker := newBroker(auth.URL, snapshot.URL, snapshot.Client(), 100*time.Millisecond, 3*time.Second)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go broker.run(ctx)
	server := httptest.NewServer(broker)
	defer server.Close()

	start := make(chan struct{})
	results := make(chan string, 2)
	for _, id := range []string{"client-a", "client-b"} {
		go func(id string) {
			<-start
			request, err := http.NewRequest(http.MethodGet, server.URL+"/rd/update/v1/policy/stream?client_id="+id+"&client_uuid="+id+"-uuid&after_revision=3", nil)
			if err != nil {
				t.Errorf("new request: %v", err)
				return
			}
			request.Header.Set("X-RustDesk-Device-Signature", "signed")
			response, err := server.Client().Do(request)
			if err != nil {
				t.Errorf("stream request: %v", err)
				return
			}
			defer response.Body.Close()
			body, err := io.ReadAll(response.Body)
			if err != nil {
				t.Errorf("read stream: %v", err)
				return
			}
			results <- string(body)
		}(id)
	}
	close(start)

	for range 2 {
		body := <-results
		if !containsAll(body, "retry: 1000", "event: update-policy", "id: 7", `"policy_revision":7`) {
			t.Fatalf("unexpected SSE body: %q", body)
		}
	}
	mu.Lock()
	if len(batchSizes) != 1 || batchSizes[0] != 2 {
		mu.Unlock()
		t.Fatalf("expected one two-stream snapshot, got %v", batchSizes)
	}
	mu.Unlock()
	broker.mu.Lock()
	defer broker.mu.Unlock()
	if len(broker.pending) != 0 || len(broker.identityUses) != 0 || broker.unsignedUses != 0 {
		t.Fatalf("completed streams leaked capacity: pending=%d identities=%v unsigned=%d", len(broker.pending), broker.identityUses, broker.unsignedUses)
	}
}

func TestBrokerRejectsRequestsDeniedByInternalAuth(t *testing.T) {
	auth := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { http.Error(w, "denied", http.StatusUnauthorized) }))
	defer auth.Close()
	broker := newBroker(auth.URL, "http://127.0.0.1:1", auth.Client(), time.Second, time.Second)
	request := httptest.NewRequest(http.MethodGet, "/rd/update/v1/policy/stream?client_id=denied&client_uuid=denied-uuid", nil)
	request.Header.Set("X-RustDesk-Device-Signature", "signed")
	response := httptest.NewRecorder()

	broker.ServeHTTP(response, request)

	if response.Code != http.StatusUnauthorized {
		t.Fatalf("expected 401, got %d", response.Code)
	}
}

func TestBrokerStreamsAdminUpdateLogWithSessionCookie(t *testing.T) {
	auth := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Query().Get("admin_path") != "/ops-console" || r.Header.Get("Cookie") != "rd_admin=session" {
			t.Fatalf("admin auth did not preserve path/cookie: path=%q cookie=%q", r.URL.Query().Get("admin_path"), r.Header.Get("Cookie"))
		}
		_ = json.NewEncoder(w).Encode(authorizedIdentity{ClientID: "client-admin", ClientUUID: "uuid-admin", Authenticated: true})
	}))
	defer auth.Close()
	snapshot := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Cookie") != "rd_admin=session" {
			t.Fatalf("admin snapshot did not preserve cookie: %q", r.Header.Get("Cookie"))
		}
		var request struct {
			AdminPath string        `json:"admin_path"`
			Streams   []streamState `json:"streams"`
		}
		if err := json.NewDecoder(r.Body).Decode(&request); err != nil {
			t.Fatalf("decode admin snapshot: %v", err)
		}
		if request.AdminPath != "/ops-console" || len(request.Streams) != 1 || request.Streams[0].Kind != "admin-log" {
			t.Fatalf("unexpected admin snapshot request: %+v", request)
		}
		_ = json.NewEncoder(w).Encode(snapshotResponse{Streams: map[string][]streamEvent{
			request.Streams[0].ConnectionID: {{ID: "100", Type: "update-log", Data: json.RawMessage(`{"revision":100}`)}},
		}})
	}))
	defer snapshot.Close()
	b := newBroker(auth.URL, snapshot.URL, snapshot.Client(), 10*time.Millisecond, time.Second)
	b.adminAuthURL, b.adminSnapshotURL = auth.URL, snapshot.URL
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go b.run(ctx)
	server := httptest.NewServer(b)
	defer server.Close()
	request, err := http.NewRequest(http.MethodGet, server.URL+"/ops-console/api/update/events/stream?client_id=client-admin&client_uuid=uuid-admin", nil)
	if err != nil {
		t.Fatalf("admin stream request setup: %v", err)
	}
	request.Header.Set("Cookie", "rd_admin=session")
	response, err := server.Client().Do(request)
	if err != nil {
		t.Fatalf("admin stream request: %v", err)
	}
	defer response.Body.Close()
	body, err := io.ReadAll(response.Body)
	if err != nil {
		t.Fatalf("read admin stream: %v", err)
	}
	if response.StatusCode != http.StatusOK || !containsAll(string(body), "event: update-log", "id: 100", `{"revision":100}`) {
		t.Fatalf("unexpected admin stream: status=%d body=%q", response.StatusCode, body)
	}
}

func TestResumeRevisionAcceptsLegacyCommandEventID(t *testing.T) {
	request := httptest.NewRequest(http.MethodGet, "/rd/update/v1/policy/stream", nil)
	request.Header.Set("Last-Event-ID", "0123456789abcdef0123456789abcdef")
	revision, err := resumeRevision(request)
	if err != nil || revision != -1 {
		t.Fatalf("expected legacy command id to request a full policy snapshot, got revision=%d err=%v", revision, err)
	}
}

func TestBrokerAllowsUnsignedIdentityWithoutInternalAuth(t *testing.T) {
	auth := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		t.Fatal("unsigned stream must not consume a PHP authorization request")
	}))
	defer auth.Close()
	snapshot := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var request snapshotRequest
		if err := json.NewDecoder(r.Body).Decode(&request); err != nil {
			t.Fatalf("decode snapshot request: %v", err)
		}
		response := snapshotResponse{Streams: map[string][]streamEvent{}}
		for _, stream := range request.Streams {
			if stream.Authenticated {
				t.Fatal("unsigned stream was incorrectly marked authenticated")
			}
			response.Streams[stream.ConnectionID] = []streamEvent{{ID: "4", Type: "update-policy", Data: json.RawMessage(`{"policy_revision":4}`)}}
		}
		_ = json.NewEncoder(w).Encode(response)
	}))
	defer snapshot.Close()
	broker := newBroker(auth.URL, snapshot.URL, auth.Client(), 10*time.Millisecond, time.Second)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go broker.run(ctx)
	request := httptest.NewRequest(http.MethodGet, "/rd/update/v1/policy/stream?client_id=unsigned&client_uuid=unsigned-uuid", nil)
	response := httptest.NewRecorder()
	broker.ServeHTTP(response, request)
	if response.Code != http.StatusOK || !containsAll(response.Body.String(), "event: update-policy", `"policy_revision":4`) {
		t.Fatalf("expected unsigned compatibility stream, got status=%d body=%q", response.Code, response.Body.String())
	}
}

func TestBrokerRejectsAtCapacityWithoutCallingPHP(t *testing.T) {
	auth := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		t.Fatal("capacity rejection must happen before PHP authorization")
	}))
	defer auth.Close()
	snapshot := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		t.Fatal("capacity rejection must not consume a PHP snapshot request")
	}))
	defer snapshot.Close()

	broker := newBroker(auth.URL, snapshot.URL, auth.Client(), time.Second, time.Second)
	broker.maxStreams = 0
	request := httptest.NewRequest(http.MethodGet, "/rd/update/v1/policy/stream?client_id=overflow&client_uuid=overflow-uuid", nil)
	request.Header.Set("X-RustDesk-Device-Signature", "signed")
	response := httptest.NewRecorder()
	broker.ServeHTTP(response, request)

	if response.Code != http.StatusServiceUnavailable || response.Header().Get("Retry-After") != "1" {
		t.Fatalf("expected bounded overload response, got status=%d retry=%q body=%q", response.Code, response.Header().Get("Retry-After"), response.Body.String())
	}
	broker.mu.Lock()
	defer broker.mu.Unlock()
	if len(broker.streams) != 0 || len(broker.pending) != 0 || len(broker.identityUses) != 0 || broker.unsignedUses != 0 {
		t.Fatalf("rejected request consumed long-poll capacity: streams=%d pending=%d identities=%v unsigned=%d", len(broker.streams), len(broker.pending), broker.identityUses, broker.unsignedUses)
	}
}

func TestBrokerReservesCapacityBeforeSignedAuthentication(t *testing.T) {
	authStarted := make(chan struct{}, 1)
	releaseAuth := make(chan struct{})
	var authCalls int
	var mu sync.Mutex
	auth := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		authCalls++
		mu.Unlock()
		authStarted <- struct{}{}
		<-releaseAuth
		_ = json.NewEncoder(w).Encode(authorizedIdentity{ClientID: "first", ClientUUID: "first-uuid", Authenticated: true})
	}))
	defer auth.Close()
	snapshot := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = json.NewEncoder(w).Encode(snapshotResponse{Streams: map[string][]streamEvent{}})
	}))
	defer snapshot.Close()
	broker := newBroker(auth.URL, snapshot.URL, auth.Client(), time.Second, 10*time.Millisecond)
	broker.maxStreams = 1

	firstDone := make(chan struct{})
	go func() {
		defer close(firstDone)
		request := httptest.NewRequest(http.MethodGet, "/rd/update/v1/policy/stream?client_id=first&client_uuid=first-uuid", nil)
		request.Header.Set("X-RustDesk-Device-Signature", "signed")
		broker.ServeHTTP(httptest.NewRecorder(), request)
	}()
	<-authStarted

	request := httptest.NewRequest(http.MethodGet, "/rd/update/v1/policy/stream?client_id=second&client_uuid=second-uuid", nil)
	request.Header.Set("X-RustDesk-Device-Signature", "signed")
	response := httptest.NewRecorder()
	broker.ServeHTTP(response, request)
	if response.Code != http.StatusServiceUnavailable || response.Header().Get("Retry-After") != "1" {
		t.Fatalf("expected reserved-capacity rejection, got status=%d retry=%q", response.Code, response.Header().Get("Retry-After"))
	}
	mu.Lock()
	if authCalls != 1 {
		mu.Unlock()
		t.Fatalf("capacity race reached PHP auth %d times", authCalls)
	}
	mu.Unlock()
	close(releaseAuth)
	<-firstDone

	broker.mu.Lock()
	defer broker.mu.Unlock()
	if len(broker.streams) != 0 || len(broker.pending) != 0 || len(broker.identityUses) != 0 || broker.unsignedUses != 0 {
		t.Fatalf("completed authentication leaked capacity: streams=%d pending=%d identities=%v unsigned=%d", len(broker.streams), len(broker.pending), broker.identityUses, broker.unsignedUses)
	}
}

func TestBrokerReservesCapacityForAuthenticatedDevices(t *testing.T) {
	broker := newBroker("http://127.0.0.1", "http://127.0.0.1", http.DefaultClient, time.Second, time.Second)
	broker.maxStreams = 4
	broker.maxUnsigned = 1
	if !broker.add(&streamState{ConnectionID: "1", ClientID: "legacy", ClientUUID: "u", Authenticated: false}) {
		t.Fatal("first unsigned stream should be accepted")
	}
	if broker.add(&streamState{ConnectionID: "2", ClientID: "legacy-2", ClientUUID: "u", Authenticated: false}) {
		t.Fatal("unsigned stream must not consume reserved signed capacity")
	}
	if !broker.add(&streamState{ConnectionID: "3", ClientID: "signed", ClientUUID: "u", Authenticated: true}) {
		t.Fatal("authenticated stream should use reserved capacity")
	}
	broker.remove("1")
	if !broker.add(&streamState{ConnectionID: "4", ClientID: "legacy-2", ClientUUID: "u", Authenticated: false}) {
		t.Fatal("released unsigned capacity should be reusable")
	}
}

func TestBrokerAliasesShareCanonicalUUIDCapacity(t *testing.T) {
	broker := newBroker("http://127.0.0.1", "http://127.0.0.1", http.DefaultClient, time.Second, time.Second)
	broker.maxIdentity = 1
	if !broker.add(&streamState{ConnectionID: "1", ClientID: "canonical", ClientUUID: "shared-uuid", Authenticated: true}) {
		t.Fatal("canonical stream should be accepted")
	}
	if broker.add(&streamState{ConnectionID: "2", ClientID: "RustDesk Yan", ClientUUID: "shared-uuid", Authenticated: true}) {
		t.Fatal("alias must share the canonical UUID stream limit")
	}
	broker.remove("1")
	if !broker.add(&streamState{ConnectionID: "3", ClientID: "RustDesk Yan", ClientUUID: "shared-uuid", Authenticated: true}) {
		t.Fatal("alias should reuse released canonical capacity")
	}
}

func TestBrokerEnforcesGlobalAndIdentityLimits(t *testing.T) {
	broker := newBroker("http://127.0.0.1", "http://127.0.0.1", http.DefaultClient, time.Second, time.Second)
	broker.maxStreams = 2
	broker.maxIdentity = 1
	first := &streamState{ConnectionID: "1", ClientID: "a", ClientUUID: "u"}
	if !broker.add(first) {
		t.Fatal("first stream should be accepted")
	}
	if broker.add(&streamState{ConnectionID: "2", ClientID: "a", ClientUUID: "u"}) {
		t.Fatal("duplicate identity should be rejected")
	}
	if !broker.add(&streamState{ConnectionID: "4", ClientID: "b", ClientUUID: "v"}) {
		t.Fatal("second independent stream should be accepted")
	}
	if broker.add(&streamState{ConnectionID: "5", ClientID: "c", ClientUUID: "w"}) {
		t.Fatal("stream beyond the global limit should be rejected")
	}
	broker.remove("1")
	if !broker.add(&streamState{ConnectionID: "5", ClientID: "c", ClientUUID: "w"}) {
		t.Fatal("released identity capacity should be reusable")
	}
}

func TestBrokerPollChunksMoreThanPHPStreamLimit(t *testing.T) {
	var mu sync.Mutex
	batchSizes := make([]int, 0, 6)
	snapshot := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var request snapshotRequest
		if err := json.NewDecoder(r.Body).Decode(&request); err != nil {
			t.Fatalf("decode snapshot request: %v", err)
		}
		mu.Lock()
		batchSizes = append(batchSizes, len(request.Streams))
		mu.Unlock()
		response := snapshotResponse{Streams: map[string][]streamEvent{}}
		for _, stream := range request.Streams {
			if stream.ConnectionID == "5001" {
				response.Streams[stream.ConnectionID] = []streamEvent{{ID: "9", Type: "update-policy", Data: json.RawMessage(`{"policy_revision":9}`)}}
			}
		}
		_ = json.NewEncoder(w).Encode(response)
	}))
	defer snapshot.Close()

	broker := newBroker("http://127.0.0.1", snapshot.URL, snapshot.Client(), time.Second, time.Second)
	var finalStream *streamState
	for index := 1; index <= 5001; index++ {
		id := strconv.Itoa(index)
		stream := &streamState{ConnectionID: id, ClientID: "client-" + id, ClientUUID: "uuid-" + id, events: make(chan []streamEvent, 1)}
		broker.streams[id] = stream
		if index == 5001 {
			finalStream = stream
		}
	}
	if err := broker.poll(context.Background()); err != nil {
		t.Fatalf("poll: %v", err)
	}
	mu.Lock()
	defer mu.Unlock()
	if len(batchSizes) != 6 {
		t.Fatalf("expected 6 bounded snapshots, got %v", batchSizes)
	}
	for _, size := range batchSizes {
		if size > 1000 {
			t.Fatalf("snapshot exceeded broker batch limit: %v", batchSizes)
		}
	}
	select {
	case events := <-finalStream.events:
		if len(events) != 1 || events[0].ID != "9" {
			t.Fatalf("unexpected event delivery: %#v", events)
		}
	default:
		t.Fatal("event in the final batch was not delivered")
	}
}
