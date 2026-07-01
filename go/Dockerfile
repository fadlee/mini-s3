# syntax=docker/dockerfile:1

# Alpine-based runtime image for mini-s3.
# Uses pre-built binaries from the release workflow (no Go toolchain here).
# buildx sets TARGETARCH automatically per platform (amd64 / arm64).
FROM alpine:3.20

ARG TARGETARCH
ARG VERSION=dev

# ca-certificates for HTTPS (self-upgrade fetches GitHub releases);
# tzdata for correct local timestamps in logs;
# libcap provides setcap so the non-root binary can bind to port 80.
RUN apk add --no-cache ca-certificates tzdata libcap

# Non-root user (matches the uid used by distroless-style images).
RUN addgroup -S -g 65532 mini-s3 && \
    adduser -S -D -H -u 65532 -G mini-s3 mini-s3

# Copy the pre-built binary matching the target arch.
# The workflow stages binaries at binaries/mini-s3-linux-<arch>.
COPY binaries/mini-s3-linux-${TARGETARCH} /usr/local/bin/mini-s3
RUN chmod +x /usr/local/bin/mini-s3 && \
    setcap 'cap_net_bind_service=+ep' /usr/local/bin/mini-s3

# Create data and config directories with correct ownership so the
# non-root user can read/write even without a host volume mount.
RUN mkdir -p /data /etc/mini-s3 && \
    chown -R mini-s3:mini-s3 /data /etc/mini-s3

# Default data_dir for the installer when no config exists yet.
ENV MINI_S3_DATA_DIR=/data

EXPOSE 80
VOLUME ["/data", "/etc/mini-s3"]

USER mini-s3

ENTRYPOINT ["/usr/local/bin/mini-s3"]
CMD ["--addr", ":80", "--config", "/etc/mini-s3/config.yaml"]
