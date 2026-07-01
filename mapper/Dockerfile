#
#                  Politecnico di Milano
#
#         Student: Caravano Andrea
#
#   Last modified: 07/05/2026
#
#     Description: Dockerfile for automated gNB-Core identifiers mapper for Open5GS + OpenAirInterface

FROM python:3.14-trixie

#### INSTALL DEPENDENCIES (Redis and Kubernetes APIs) ####
RUN /usr/local/bin/pip install redis==7.4.0 kubernetes==35.0.0

#### INSTALL SOME BASE SHELL UTILITIES ####
RUN /usr/bin/apt-get update && export DEBIAN_FRONTEND=noninteractive \
    && /usr/bin/apt-get dist-upgrade -y \
    && /usr/bin/apt-get -y install --no-install-recommends curl nano \
    && /usr/bin/apt-get clean \
    && /usr/bin/rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

#### COPY AND PREPARE THE MAPPER SCRIPT ####
RUN /usr/bin/mkdir -p /mapper/
COPY ./mapper.py /mapper/
WORKDIR /mapper/

ENTRYPOINT ["/usr/local/bin/python", "/mapper/mapper.py"]
