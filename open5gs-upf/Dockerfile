#
#                  Politecnico di Milano
#
#         Student: Caravano Andrea
#
#   Last modified: 23/05/2026
#
#     Description: Dockerfile for customized Open5GS Core UPF base container
#

FROM gradiant/open5gs:2.7.5

# Temporarily switch to the root user to allow for dependencies installation
USER root

#### INSTALL DEPENDENCIES (Python and related packages) ####
RUN /usr/bin/apt-get update && export DEBIAN_FRONTEND=noninteractive \
    && /usr/bin/apt-get dist-upgrade -y \
    && /usr/bin/apt-get -y install --no-install-recommends python3 python3-fastapi python3-uvicorn python3-pydantic \
    && /usr/bin/apt-get clean \
    && /bin/rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

#### COPY AND PREPARE THE CONTROLLER SCRIPT ####
RUN /bin/mkdir -p /controller/
COPY ./controller.py /controller/

USER open5gs

RUN /bin/echo "Container image creation completed sucessfully!"
