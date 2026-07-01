# syntax=docker/dockerfile:1

# Build stage
FROM golang:1.26-alpine AS builder

WORKDIR /src

# Cache deps first
COPY go.mod go.sum ./
RUN go mod download

# Copy source
COPY . .

ARG VERSION=dev
ENV CGO_ENABLED=0 GOOS=linux

RUN go build -trimpath \
      -ldflags "-s -w -X main.Version=${VERSION}" \
      -o /out/mini-s3 ./cmd/mini-s3

# Runtime stage: minimal static image
FROM scratch

# Non-root user (65532:65532 — distroless-style uid)
COPY --from=builder /out/mini-s3 /usr/local/bin/mini-s3

# Default config lives in /etc/mini-s3; data in /data.
# Both are overridable via --config / --addr flags or env vars.
EXPOSE 9000

# VOLUME ["/data", "/etc/mini-s3"]

ENTRYPOINT ["/usr/local/bin/mini-s3"]
CMD ["--addr", ":9000", "--config", "/etc/mini-s3/config.yaml"]
