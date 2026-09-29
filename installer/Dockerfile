FROM docker:27-cli

RUN apk add --no-cache python3
WORKDIR /opt/provisioner
COPY provisioner.py /opt/provisioner/provisioner.py
EXPOSE 8080
ENTRYPOINT ["python3", "/opt/provisioner/provisioner.py"]
