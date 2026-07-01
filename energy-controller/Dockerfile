#
#                  Politecnico di Milano
#
#         Student: Caravano Andrea
#
#   Last modified: 27/06/2026
#
#     Description: Dockerfile for power governance framework for the 5G Neutral Host management platform
#

FROM python:3.14-alpine

#### INSTALL BASE PYTHON DEPENDENCIES ####
RUN /usr/local/bin/pip install fastapi uvicorn redis kubernetes pydantic asyncpg

COPY controller.py /app/controller.py

RUN echo "Container image creation completed sucessfully!"

WORKDIR /app

ENTRYPOINT ["/usr/local/bin/uvicorn", "controller:app", "--host", "0.0.0.0", "--port", "8000"]
