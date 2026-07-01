# Neutral Host management platform

Developed as part of the M.Sc. in Computer Science and Engineering thesis work "A platform for efficient and observable management of multi-tenant Neutral Host deployments" by Andrea Caravano

### License and publishing notes

See [license file](LICENSE)

### Usage

This repository contains all the elements needed to deploy the 5G Neutral Host management platform developed as part of the M.Sc. Thesis work "A platform for efficient and observable management of multi-tenant Neutral Host deployments" (... handle to be added ...) by Andrea Caravano.

After completing the setup of [PostgreSQL](postgres/deploy.sh), [Redis](redis/deploy.sh) and [Apache Web Server](apache) the platform can be directly accessed via web browser on the chosen
public-facing domain (see [apache/external/deploy.yaml](apache/external/deploy.yaml)).

The public-facing domain can be either hosted on private reverse proxy solutions (e.g. [Caddy](https://github.com/caddyserver/caddy), [Nginx Proxy Manager](https://github.com/NginxProxyManager/nginx-proxy-manager)) or locally resolved via a patch to `/etc/hosts` or local DNS server.

#### gNB-Core mapper

OpenAirInterface gNB's C-RNTI and Open5GS AMF's UE-NGAP-ID are the two main identifiers that directly correlate a user identity (IMSI) and its metrics, performance and consumption parameters, through the respective container logs.

The package provided through GitHub's Container Registry **should be used in combination** with the complete Kubernetes Open5GS + OpenAirInterface deployment available [here](to-complete).

If used in other contexts, pay attention to **properly customize naming conventions** adopted in [the script](mapper/mapper.py), according to the interested setup.

The **pre-configured package** is available through GitHub's Container Registry: ```ghcr.io/andreacaravano/oai-gnb-core-mapper:latest```

To directly **deploy the pre-configured package** on a standard Kubernetes/K3s **cluster**, the cumulative ```deploy.yaml``` can be used:
```kubectl apply -f deploy.yaml```

#### Ansible playbook

The current playbook target is Ubuntu Server 24.04 LTS.

Install Ubuntu Server 24.04 LTS, enabling OpenSSH server upon installation process.

Open the present repository in development containers-enabled environments (e.g. Visual Studio Code, Zed or JetBrains IDEs) and, when prompted, open the repository inside the [development container](.devcontainer), which carries all dependencies for both Ansible and Kubernetes cluster management.

Install all the required collections via `ansible-galaxy install -r collections/requirements.yaml`

And, when ready, run the playbook on the system through:

`ansible-playbook site.yaml -i inventory/hosts.ini -k -K`

It will prompt for both the SSH password and super-user duties (make sure that `sudo` is available, and the chosen user is added to the `sudo` group via `usermod -aG sudo $USER`).

Once completed, the cluster can managed directly via SSH on the host or conveniently through the same development container, after having copied `/home/$USER/.kube/config` to `.devcontainer/kubeconfig` in the current repository.

Alternatively, Rancher web GUI is also made available through the chosen public-facing domain address.
