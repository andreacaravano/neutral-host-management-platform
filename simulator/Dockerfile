#
#                  Politecnico di Milano
#
#         Student: Caravano Andrea
#
#   Last modified: 27/06/2026
#
#     Description: Dockerfile for live simulative demo for the 5G Neutral Host management platform
#

FROM python:3.14

#### INSTALL BASE PYTHON DEPENDENCIES ####
RUN /usr/local/bin/pip install psycopg2 redis

COPY simulator.py /app/simulator.py

RUN echo "Container image creation completed sucessfully!"

ENTRYPOINT ["python", "/app/simulator.py"]
