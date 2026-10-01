package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"syscall"
	"time"
)

type streamState struct {
	ConnectionID  string `json:"connection_id"`
	ClientID      string `json:"client_id"`
	ClientUUID    string `json:"client_uuid"`
	AfterRevision int64  `json:"after_revision"`
	Authenticated bool   `json:"authenticated"`
	Channel       string `json:"channel"`
	events        chan []streamEvent
}

type streamEvent struct {
	ID   string          `json:"id"`
	Type string          `json:"type"`
	Data json.RawMessage `json:"data"`
}

type snapshotRequest struct {
	Streams []streamState `json:"streams"`
}
type snapshotResponse struct {
	Streams map[string][]streamEvent `json:"streams"`
}

type broker struct {
	authURL      string
	snapshotURL  string
	client       *http.Client
	pollInterval time.Duration
	maxLifetime  time.Duration
	nextID       atomic.Uint64
	mu           sync.Mutex
	streams      map[string]*streamState
	pending      map[string]*streamState
	identityUses map[string]int
	maxStreams   int
	maxIdentity  int
	maxUnsigned  int
	unsignedUses int
	batchSize    int
}

func newBroker(authURL, snapshotURL string, client *http.Client, pollInterval, maxLifetime time.Duration) *broker {
	return &broker{
		authURL: authURL, snapshotURL: snapshotURL, client: client, pollInterval: pollInterval, maxLifetime: maxLifetime,
		streams: make(map[string]*streamState), pending: make(map[string]*streamState), identityUses: make(map[string]int),
		maxStreams: 4096, maxIdentity: 4, maxUnsigned: 512, batchSize: 1000,
	}
}

func (b *broker) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet || r.URL.Path != "/rd/update/v1/policy/stream" {
		http.NotFound(w, r)
		return
	}
	identity, status, err := requestedIdentity(r)
	if err != nil {
		http.Error(w, err.Error(), status)
		return
	}
	afterRevision, err := resumeRevision(r)
	if err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	channel := r.URL.Query().Get("channel")
	if channel == "" {
		channel = "stable"
	}
	if channel != "stable" && channel != "beta" {
		http.Error(w, "invalid update channel", http.StatusUnprocessableEntity)
		return
	}
	flusher, ok := w.(http.Flusher)
	if !ok {
		http.Error(w, "streaming is unavailable", http.StatusInternalServerError)
		return
	}
	connectionID := strconv.FormatUint(b.nextID.Add(1), 10)
	identity.Authenticated = strings.TrimSpace(r.Header.Get("X-RustDesk-Device-Signature")) != ""
	state := &streamState{ConnectionID: connectionID, ClientID: identity.ClientID, ClientUUID: identity.ClientUUID, AfterRevision: afterRevision, Authenticated: identity.Authenticated, Channel: channel, events: make(chan []streamEvent, 1)}
	if !b.reserve(state) {
		writeOverloaded(w)
		return
	}
	defer b.remove(connectionID)
	if identity.Authenticated {
		identity, status, err = b.authorize(r)
		if err != nil {
			http.Error(w, http.StatusText(status), status)
			return
		}
	}
	b.commit(connectionID, identity)
	prepareStreamResponse(w)
	flusher.Flush()
	heartbeat := time.NewTicker(2 * time.Second)
	defer heartbeat.Stop()
	deadline := time.NewTimer(b.maxLifetime)
	defer deadline.Stop()
	for {
		select {
		case events := <-state.events:
			writeStreamEvents(w, events)
			flusher.Flush()
			return
		case <-heartbeat.C:
			fmt.Fprint(w, ": heartbeat\n\n")
			flusher.Flush()
		case <-deadline.C:
			return
		case <-r.Context().Done():
			return
		}
	}
}

func writeOverloaded(w http.ResponseWriter) {
	w.Header().Set("Retry-After", "1")
	http.Error(w, http.StatusText(http.StatusServiceUnavailable), http.StatusServiceUnavailable)
}

func prepareStreamResponse(w http.ResponseWriter) {
	w.Header().Set("Content-Type", "text/event-stream; charset=utf-8")
	w.Header().Set("Cache-Control", "no-cache")
	w.Header().Set("X-Accel-Buffering", "no")
	fmt.Fprint(w, "retry: 1000\n\n")
}

func writeStreamEvents(w io.Writer, events []streamEvent) {
	for _, event := range events {
		fmt.Fprintf(w, "id: %s\nevent: %s\ndata: %s\n\n", event.ID, event.Type, event.Data)
	}
}

type authorizedIdentity struct {
	ClientID      string `json:"client_id"`
	ClientUUID    string `json:"client_uuid"`
	Authenticated bool   `json:"authenticated"`
}

func requestedIdentity(source *http.Request) (authorizedIdentity, int, error) {
	identity := authorizedIdentity{
		ClientID:   strings.TrimSpace(source.URL.Query().Get("client_id")),
		ClientUUID: strings.TrimSpace(source.URL.Query().Get("client_uuid")),
	}
	if identity.ClientID == "" {
		identity.ClientID = strings.TrimSpace(source.URL.Query().Get("id"))
	}
	if identity.ClientUUID == "" {
		identity.ClientUUID = strings.TrimSpace(source.URL.Query().Get("uuid"))
	}
	if identity.ClientID == "" || len(identity.ClientID) > 128 || identity.ClientUUID == "" || len(identity.ClientUUID) > 256 {
		return authorizedIdentity{}, http.StatusUnprocessableEntity, errors.New("invalid client identity")
	}
	return identity, http.StatusOK, nil
}

func (b *broker) authorize(source *http.Request) (authorizedIdentity, int, error) {
	request, err := http.NewRequestWithContext(source.Context(), http.MethodGet, b.authURL+"?"+source.URL.RawQuery, nil)
	if err != nil {
		return authorizedIdentity{}, http.StatusBadGateway, err
	}
	for _, name := range []string{"Last-Event-ID", "X-RustDesk-Device-ID", "X-RustDesk-Device-Public-Key", "X-RustDesk-Device-Timestamp", "X-RustDesk-Device-Nonce", "X-RustDesk-Device-Signature"} {
		if value := source.Header.Get(name); value != "" {
			request.Header.Set(name, value)
		}
	}
	response, err := b.client.Do(request)
	if err != nil {
		return authorizedIdentity{}, http.StatusBadGateway, err
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK {
		return authorizedIdentity{}, response.StatusCode, errors.New("stream authorization rejected")
	}
	var identity authorizedIdentity
	if err := json.NewDecoder(io.LimitReader(response.Body, 4096)).Decode(&identity); err != nil {
		return authorizedIdentity{}, http.StatusBadGateway, errors.New("stream authorization returned invalid JSON")
	}
	identity.ClientID = strings.TrimSpace(identity.ClientID)
	identity.ClientUUID = strings.TrimSpace(identity.ClientUUID)
	if identity.ClientID == "" || len(identity.ClientID) > 128 || identity.ClientUUID == "" || len(identity.ClientUUID) > 256 {
		return authorizedIdentity{}, http.StatusBadGateway, errors.New("stream authorization returned invalid identity")
	}
	return identity, http.StatusOK, nil
}

func resumeRevision(r *http.Request) (int64, error) {
	value := r.URL.Query().Get("after_revision")
	if value == "" {
		value = r.Header.Get("Last-Event-ID")
	}
	if value == "" {
		return -1, nil
	}
	if len(value) == 32 {
		for _, char := range value {
			if !strings.ContainsRune("0123456789abcdef", char) {
				return 0, errors.New("invalid stream revision")
			}
		}
		return -1, nil
	}
	revision, err := strconv.ParseInt(value, 10, 64)
	if err != nil || revision < 0 {
		return 0, errors.New("invalid stream revision")
	}
	return revision, nil
}

func (b *broker) remove(connectionID string) {
	b.mu.Lock()
	b.removeLocked(connectionID)
	b.mu.Unlock()
}

func (b *broker) removeLocked(connectionID string) {
	stream := b.streams[connectionID]
	if stream != nil {
		delete(b.streams, connectionID)
	} else if stream = b.pending[connectionID]; stream != nil {
		delete(b.pending, connectionID)
	}
	if stream != nil {
		b.releaseCapacityLocked(stream)
	}
}

func (b *broker) releaseCapacityLocked(stream *streamState) {
	decrement(b.identityUses, streamIdentity(stream.ClientUUID))
	if !stream.Authenticated {
		b.unsignedUses--
	}
}

func (b *broker) hasCapacityLocked(identity string, authenticated bool) bool {
	return len(b.streams)+len(b.pending) < b.maxStreams && b.identityUses[identity] < b.maxIdentity && (authenticated || b.unsignedUses < b.maxUnsigned)
}

func (b *broker) reserve(stream *streamState) bool {
	b.mu.Lock()
	defer b.mu.Unlock()
	identity := streamIdentity(stream.ClientUUID)
	if !b.hasCapacityLocked(identity, stream.Authenticated) {
		return false
	}
	b.pending[stream.ConnectionID] = stream
	b.identityUses[identity]++
	if !stream.Authenticated {
		b.unsignedUses++
	}
	return true
}

func (b *broker) commit(connectionID string, identity authorizedIdentity) {
	b.mu.Lock()
	defer b.mu.Unlock()
	stream := b.pending[connectionID]
	if stream == nil {
		return
	}
	delete(b.pending, connectionID)
	oldIdentity := streamIdentity(stream.ClientUUID)
	newIdentity := streamIdentity(identity.ClientUUID)
	if oldIdentity != newIdentity {
		decrement(b.identityUses, oldIdentity)
		b.identityUses[newIdentity]++
		stream.ClientID = identity.ClientID
		stream.ClientUUID = identity.ClientUUID
	}
	stream.Authenticated = identity.Authenticated
	b.streams[connectionID] = stream
}

func streamIdentity(clientUUID string) string {
	return clientUUID
}

func (b *broker) add(stream *streamState) bool {
	if !b.reserve(stream) {
		return false
	}
	b.commit(stream.ConnectionID, authorizedIdentity{ClientID: stream.ClientID, ClientUUID: stream.ClientUUID, Authenticated: stream.Authenticated})
	return true
}

func decrement(counts map[string]int, key string) {
	if counts[key] <= 1 {
		delete(counts, key)
	} else {
		counts[key]--
	}
}

func (b *broker) run(ctx context.Context) {
	ticker := time.NewTicker(b.pollInterval)
	defer ticker.Stop()
	for {
		select {
		case <-ticker.C:
			if err := b.poll(ctx); err != nil && !errors.Is(err, context.Canceled) {
				log.Printf("stream snapshot failed: %v", err)
			}
		case <-ctx.Done():
			return
		}
	}
}

func (b *broker) poll(ctx context.Context) error {
	b.mu.Lock()
	streams := make([]streamState, 0, len(b.streams))
	for _, stream := range b.streams {
		streams = append(streams, streamState{ConnectionID: stream.ConnectionID, ClientID: stream.ClientID, ClientUUID: stream.ClientUUID, AfterRevision: stream.AfterRevision, Authenticated: stream.Authenticated, Channel: stream.Channel})
	}
	b.mu.Unlock()
	for start := 0; start < len(streams); start += b.batchSize {
		end := min(start+b.batchSize, len(streams))
		if err := b.pollBatch(ctx, snapshotRequest{Streams: streams[start:end]}); err != nil {
			return err
		}
	}
	return nil
}

func (b *broker) pollBatch(ctx context.Context, requestBody snapshotRequest) error {
	snapshot, err := b.snapshot(ctx, requestBody)
	if err != nil {
		return err
	}
	b.mu.Lock()
	defer b.mu.Unlock()
	for connectionID, events := range snapshot.Streams {
		if len(events) == 0 {
			continue
		}
		stream := b.streams[connectionID]
		if stream == nil {
			continue
		}
		b.removeLocked(connectionID)
		stream.events <- events
	}
	return nil
}

func (b *broker) snapshot(ctx context.Context, requestBody snapshotRequest) (snapshotResponse, error) {
	payload, err := json.Marshal(requestBody)
	if err != nil {
		return snapshotResponse{}, err
	}
	request, err := http.NewRequestWithContext(ctx, http.MethodPost, b.snapshotURL, bytes.NewReader(payload))
	if err != nil {
		return snapshotResponse{}, err
	}
	request.Header.Set("Content-Type", "application/json")
	response, err := b.client.Do(request)
	if err != nil {
		return snapshotResponse{}, err
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK {
		return snapshotResponse{}, fmt.Errorf("snapshot returned HTTP %d", response.StatusCode)
	}
	var snapshot snapshotResponse
	if err := json.NewDecoder(response.Body).Decode(&snapshot); err != nil {
		return snapshotResponse{}, err
	}
	return snapshot, nil
}

func containsAll(value string, needles ...string) bool {
	for _, needle := range needles {
		if !strings.Contains(value, needle) {
			return false
		}
	}
	return true
}

func main() {
	authURL := envOr("RUSTDESK_STREAM_AUTH_URL", "http://127.0.0.1:8081/_rustdesk-stream-internal/auth")
	snapshotURL := envOr("RUSTDESK_STREAM_SNAPSHOT_URL", "http://127.0.0.1:8081/_rustdesk-stream-internal/snapshot")
	listen := envOr("RUSTDESK_STREAM_LISTEN", "127.0.0.1:8787")
	b := newBroker(authURL, snapshotURL, &http.Client{Timeout: 5 * time.Second}, 500*time.Millisecond, 15*time.Second)
	ctx, cancel := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer cancel()
	go b.run(ctx)
	server := &http.Server{Addr: listen, Handler: b, ReadHeaderTimeout: 5 * time.Second, IdleTimeout: 30 * time.Second}
	go func() {
		<-ctx.Done()
		shutdown, stop := context.WithTimeout(context.Background(), 5*time.Second)
		defer stop()
		_ = server.Shutdown(shutdown)
	}()
	log.Printf("RustDesk stream broker listening on %s", listen)
	if err := server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		log.Fatal(err)
	}
}

func envOr(name, fallback string) string {
	if value := strings.TrimSpace(os.Getenv(name)); value != "" {
		return value
	}
	return fallback
}
