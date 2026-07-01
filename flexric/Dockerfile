#
#                  Politecnico di Milano
#
#         Student: Caravano Andrea
#
#   Last modified: 17/05/2026
#
#     Description: Dockerfile for customized OAI FlexRIC distribution for the 5G Neutral Host management platform
#

#/*
# * SPDX-License-Identifier: LicenseRef-CSSL-1.0
# */

#
#---------------------------------------------------------------------
#
# Dockerfile for the Open-Air-Interface FlexRIC service
#   Valid for Ubuntu-22.04 (jammy)
#
#---------------------------------------------------------------------
# BUILDER IMAGE
#---------------------------------------------------------------------
ARG BASE_IMAGE=ubuntu:jammy

FROM $BASE_IMAGE AS oai-flexric
ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=Europe/Rome

#install developers pkg/repo
RUN apt-get update && \
    DEBIAN_FRONTEND=noninteractive apt-get upgrade --yes && \
    DEBIAN_FRONTEND=noninteractive apt-get install --yes \
       build-essential \
       libsctp-dev \
       git \
       wget \
       tar \
       m4 \
       automake \
       libtool \
       python3 \
       cmake \
       cmake-curses-gui \
       bison \
       flex \
       gdb \
       libpcre2-dev \
       python3-dev \
       python3-pip \
       gcc-12 \
       g++-12 \
       mold \
       ninja-build && \
    apt-get clean

RUN git clone https://github.com/swig/swig.git && \
    cd swig && \
    git checkout release-4.1 && \
    ./autogen.sh && \
    ./configure --prefix=/usr/ && \
    make -j8 && \
    make install && \
    ldconfig

RUN rm -rf /tmp/asn1c && \
    git clone https://github.com/mouse07410/asn1c /tmp/asn1c && \
    cd /tmp/asn1c && \
    git checkout 940dd5fa9f3917913fd487b13dfddfacd0ded06e && \
    # Showing which version is used
    git log -n1 && \
    autoreconf -iv && \
    ./configure --prefix /opt/asn1c/ && \
    make -j`nproc` && \
    make install

ARG E2AP_VERSION=E2AP_V3
ARG KPM_VERSION=KPM_V3_00

WORKDIR /

RUN git clone "https://gitlab.eurecom.fr/mosaic5g/flexric.git"

WORKDIR /flexric

# To ensure reproducibility, a fixed commit had to be chosen: 7805d63c3157dee630f11d6eef2f5ed540f5209a have been made on 05/05/2026
# For anyone using this containerized environment in the future: choose a reasonably-recent and tested commit to build from.
RUN git reset --hard 7805d63c3157dee630f11d6eef2f5ed540f5209a

# Inject our custom xApp in the build cycle!
RUN mkdir -p /flexric/examples/xApp/c/kpm_custom/
COPY CMakeLists.txt /flexric/examples/xApp/c/kpm_custom/CMakeLists.txt
COPY kpm_custom.c /flexric/examples/xApp/c/kpm_custom/kpm_custom.c

RUN echo "add_subdirectory(kpm_custom)" >> /flexric/examples/xApp/c/CMakeLists.txt

RUN mkdir build && \
    cd build && \
    cmake -GNinja -DCMAKE_BUILD_TYPE=Release \
          -DE2AP_VERSION=$E2AP_VERSION \
          -DKPM_VERSION=$KPM_VERSION \
          -DCMAKE_C_COMPILER=gcc-12 \
          -DCMAKE_CXX_COMPILER=g++-12 .. && \
    ninja && \
    ninja install && \
    echo "--- Check shared objects dependencies for executable nearRT-RIC ---" && \
    ldd /flexric/build/examples/ric/nearRT-RIC && \
    echo "--- Check shared objects dependencies for xApp shared library ---" && \
    ldd /flexric/build/src/xApp/libe42_xapp_shared.so

RUN mkdir -p /usr/local/bin
RUN cp -r /flexric/build/examples/ric/nearRT-RIC /usr/local/bin/nearRT-RIC

## Copy xApps
RUN mkdir -p /usr/local/flexric/
RUN cp -r /flexric/build/examples/xApp /usr/local/flexric/xApp

## Copy E2 agent emulators
RUN mkdir -p /usr/local/flexric/emulator/
RUN cp -r /flexric/build/examples/emulator/agent /usr/local/flexric/emulator/agent

RUN ldconfig && \
    echo "--- Check shared objects dependencies for executable nearRT-RIC ---" && \
    ldd /usr/local/bin/nearRT-RIC && \
    echo "--- Check shared objects dependencies for xApp shared library ---" && \
    ldd /flexric/build/src/xApp/libe42_xapp_shared.so && \
    echo "--- Check shared objects dependencies for flexric shared libraries ---" && \
    ldd /usr/local/lib/flexric/*.so

# Patch the default IP address (should not be needed if the official Helm chart is deployed)
# RUN sed -i 's/NEAR_RIC_IP = 127.0.0.1/NEAR_RIC_IP = 192.168.1.91/' /usr/local/etc/flexric/flexric.conf

COPY entrypoint.sh /usr/local/flexric/entrypoint.sh
RUN chmod +x /usr/local/flexric/entrypoint.sh

RUN echo "Container image creation completed sucessfully!"

## E2
EXPOSE 36421/sctp
## E42
EXPOSE 36422/sctp

# CMD ["stdbuf", "-o0", "nearRT-RIC"]

CMD [ "/usr/local/flexric/entrypoint.sh" ]
