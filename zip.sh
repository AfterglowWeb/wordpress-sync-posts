#!/bin/bash
rm sync-posts.zip
zip -r sync-posts.zip . -x "*.zip" -x "*.tar" -x "*.tar.gz" -x "*.env" -x "*.env*" -x ".git/*" -x ".gitignore" -x "*.config.js" -x "node_modules/*" -x ".DS_Store" -x "._*"