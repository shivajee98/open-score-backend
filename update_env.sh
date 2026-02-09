#!/bin/bash

# Script to update FCM environment variables on Hostinger
ENV_FILE="${1:-.env}"

# Function to update or append a key-value pair
update_key() {
    local key=$1
    local value=$2
    
    if grep -q "^${key}=" "$ENV_FILE"; then
        # Update existing key
        # Use a different delimiter (|) in case the value contains /
        sed -i "s|^${key}=.*|${key}=${value}|" "$ENV_FILE"
        echo "Updated ${key}"
    else
        # Append new key
        echo "${key}=${value}" >> "$ENV_FILE"
        echo "Added ${key}"
    fi
}

# Ensure the .env file exists
if [ ! -f "$ENV_FILE" ]; then
    echo "Error: .env file not found"
    exit 1
fi

# FCM Keys identified from screenshots and configuration
update_key "FCM_SERVER_KEY" "AIzaSyAl2w3JHMEBG7lDH7GdyaAFvqMCFdahEgs"
update_key "FCM_SENDER_ID" "484983197738"
update_key "FCM_VAPID_PUBLIC_KEY" "BHvmCGQ_Ro9oPKZ7VZTf9TEP9Q_ZaPMcF0U-kvKiufXRbVMp-DuqIvnP8Q9BMGHRT03NJNi2w0Qlpo9OTMj5xVQ"

# KYC URL Update
update_key "KYC_FORM_URL" "https://kyc.msmeloan.sbs"

echo "Environment variables update complete."
