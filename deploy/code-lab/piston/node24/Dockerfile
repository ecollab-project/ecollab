# EXPERIMENTAL STAGING IMAGE — never used by production compose.yml.
# Exact upstream source commit. The grading installer must accept both original SHA256 hashes.
FROM debian:bookworm-slim AS isolate-build
ARG ISOLATE_COMMIT=af6db68042c3aa0ded80787fbb78bc0846ea2114
RUN apt-get update && apt-get install -y --no-install-recommends \
      git ca-certificates make gcc libc6-dev libcap-dev && \
    rm -rf /var/lib/apt/lists/* && \
    git clone https://github.com/envicutor/isolate.git /tmp/isolate && \
    cd /tmp/isolate && git checkout --detach "${ISOLATE_COMMIT}" && \
    test "$(git rev-parse HEAD)" = "${ISOLATE_COMMIT}" && \
    make -j2 install

FROM node:24.22.1-bookworm-slim
ARG PISTON_COMMIT=de2b365ac759670a3a0d13ea208a0869a92c7e64
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update && apt-get install -y --no-install-recommends \
      git ca-certificates python3 make g++ \
      bash coreutils util-linux tar binutils build-essential locales \
      libxml2 libevent-2.1-7 libgmp10 libncurses6 libedit2 \
      libseccomp2 rename procps libreadline8 libblas3 liblapack3 \
      libarpack2 libfftw3-double3 libglpk40 libqhull-r8.0 \
      libsuitesparseconfig5 libsundials-ida6 libcap2 && \
    rm -rf /var/lib/apt/lists/* && \
    useradd -m -s /bin/bash piston && \
    sed -i '/en_US.UTF-8/s/^# //g' /etc/locale.gen && locale-gen
COPY --from=isolate-build /usr/local/bin/isolate /usr/local/bin/isolate
COPY --from=isolate-build /usr/local/etc/isolate /usr/local/etc/isolate
RUN git clone https://github.com/engineer-man/piston.git /tmp/piston && \
    cd /tmp/piston && git checkout --detach "${PISTON_COMMIT}" && \
    test "$(git rev-parse HEAD)" = "${PISTON_COMMIT}" && \
    mkdir -p /piston_api && cp -a /tmp/piston/api/. /piston_api/ && \
    rm -rf /tmp/piston
WORKDIR /piston_api
# Do not disable native dependency build scripts; incompatible addons must fail the build.
RUN npm ci --omit=dev --no-audit --no-fund
COPY grading /ecollab-grade
# Fail closed: upstream source hashes and extension anchors MUST match the reviewed originals.
RUN python3 /ecollab-grade/install.py && \
    test "$(node --version)" = "v24.22.1" && \
    node --check src/job.js && node --check src/api/v2.js
RUN mkdir -p /piston/packages && chown -R piston:piston /piston /piston_api && \
    chmod +x /piston_api/src/docker-entrypoint.sh
EXPOSE 2000
CMD ["/piston_api/src/docker-entrypoint.sh"]
