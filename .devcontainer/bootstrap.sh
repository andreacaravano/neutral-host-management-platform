#
#                  Politecnico di Milano
#
#         Student: Caravano Andrea
#
#   Last modified: 16/01/2026
#
#     Description: Bootstrap script for the development container for Kubernetes management and Python data analysis in Visual Studio Code
#

#!/bin/sh

#### PREPARE KUBERNETES MANAGEMENT ENVIRONMENT ####
/usr/bin/mkdir -p $HOME/.kube/
/usr/bin/ln -sf $(pwd)/.devcontainer/kubeconfig $HOME/.kube/config

#### PREPARE ANSIBLE CONFIGURATION ####
/usr/bin/ln -sf $(pwd)/.devcontainer/ansible.cfg $HOME/.ansible.cfg
