#!/bin/bash


if [ -z "$1" ]; then
  echo "Error: Please provide a name for the tarball."
  exit 1
fi


TARBALL_NAME="$1.tgz"


DESTINATION="/var/www/sample"


FILES=$(jq -r '.files[]' deploy_config.json)


echo "Creating tarball: $TARBALL_NAME"
tar -czf "$TARBALL_NAME" deploy_config.json $FILES


echo "Tarball created successfully."


scp "$TARBALL_NAME" yh36@172.29.85.9:/var/deployment/temp

echo "Tarball sent successfully."

