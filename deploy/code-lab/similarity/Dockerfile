FROM eclipse-temurin:25-jdk AS jre
FROM python:3.12-slim-trixie
RUN apt-get update && apt-get install -y --no-install-recommends libstdc++6 && rm -rf /var/lib/apt/lists/*
COPY --from=jre /opt/java/openjdk /opt/java/openjdk
WORKDIR /runner
COPY jplag.jar /runner/jplag.jar
COPY bridge.py /runner/bridge.py
USER 10001:10001
CMD ["python","-B","/runner/bridge.py"]
