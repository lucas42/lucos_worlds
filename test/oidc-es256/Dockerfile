FROM python:3.13-slim
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY mock_idp.py driver.py ./
COPY mock_idp_cert.pem mock_idp_key.pem /certs/
