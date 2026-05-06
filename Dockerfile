# Force rebuild: templates fix
FROM python:3.12-slim
WORKDIR /app
RUN apt-get update && apt-get install -y --no-install-recommends && rm -rf /var/lib/apt/lists/*
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY *.py ./
COPY templates/ templates/
ENV DATA_DIR=/data
RUN mkdir -p /data
EXPOSE 5000
CMD ["gunicorn","--bind","0.0.0.0:5000","--workers","2","--timeout","60","app:app"]
