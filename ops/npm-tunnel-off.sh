#!/usr/bin/env bash
npm config set registry https://registry.npmjs.org/
echo "registry 원복: $(npm config get registry)"
