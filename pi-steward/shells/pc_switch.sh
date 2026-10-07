#!/bin/bash

PC_SWITCH_SCRIPT_PATH="${1:?外部Pythonスクリプトパスが未指定です}"
PC_SWITCH_DEVICE_ID="${2:?デバイスIDが未指定です}"

while :; do
  EXEC=$(/usr/bin/python3 "${PC_SWITCH_SCRIPT_PATH}" -d "${PC_SWITCH_DEVICE_ID}" press)
  if [[ $EXEC =~ Connected ]]; then
    exit 0
  fi
done
