#
#                  Politecnico di Milano
#
#         Student: Caravano Andrea
#
#   Last modified: 15/05/2026
#
#     Description: Dockerfile for development container for Kubernetes management and Python data analysis in supported Development Containers environments (e.g. Visual Studio Code)

FROM mcr.microsoft.com/devcontainers/miniconda

#### INSTALL DEPENDENCIES ####
# RUN conda update --all --yes \
RUN /opt/conda/bin/conda install --yes --channel conda-forge notebook nest-asyncio pandas numpy scapy matplotlib scikit-learn ansible-lint \
    && /opt/conda/bin/conda clean -afy \
    && /opt/conda/bin/pip install --no-cache-dir redis kubernetes

#### INSTALL THE FULL ANSIBLE PACKAGE (Ansible automation system) ####
RUN /usr/local/py-utils/bin/pipx install --include-deps ansible

#### INSTALL KUBECTL AND DEPENDENCIES (Kubernetes CLI) ####
RUN /usr/bin/apt-get update && export DEBIAN_FRONTEND=noninteractive \
    && /usr/bin/apt-get -y install --no-install-recommends apt-transport-https ca-certificates curl gnupg

RUN /usr/bin/mkdir -p -m 755 /etc/apt/keyrings
RUN /usr/bin/curl -fsSL https://pkgs.k8s.io/core:/stable:/v1.36/deb/Release.key | /usr/bin/gpg --dearmor -o /etc/apt/keyrings/kubernetes-apt-keyring.gpg
RUN /usr/bin/chmod 644 /etc/apt/keyrings/kubernetes-apt-keyring.gpg
RUN /usr/bin/echo 'deb [signed-by=/etc/apt/keyrings/kubernetes-apt-keyring.gpg] https://pkgs.k8s.io/core:/stable:/v1.36/deb/ /' | /usr/bin/tee /etc/apt/sources.list.d/kubernetes.list
RUN /usr/bin/chmod 644 /etc/apt/sources.list.d/kubernetes.list

RUN /usr/bin/apt-get update && export DEBIAN_FRONTEND=noninteractive \
    && /usr/bin/apt-get -y install --no-install-recommends kubectl \
    && /usr/bin/apt-get clean \
    && /usr/bin/rm -rf /var/lib/apt/lists/*

#### INSTALL THE HELM PACKAGE MANAGER ####
RUN /usr/bin/curl https://raw.githubusercontent.com/helm/helm/main/scripts/get-helm-4 | /usr/bin/bash
