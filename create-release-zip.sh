#!/bin/bash
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SOURCE_DIR="$SCRIPT_DIR/postishipping"
OUTPUT_FILE="$SCRIPT_DIR/postishipping.zip"

if [ ! -d "$SOURCE_DIR" ]; then
    echo "Error: Directory '$SOURCE_DIR' not found."
    exit 1
fi

# Remove old zip if exists
rm -f "$OUTPUT_FILE"

cd "$SCRIPT_DIR"
# git archive only includes tracked files (so .gitignore'd files like
# posticonfig.json/config.xml are skipped) and honors export-ignore in .gitattributes
git archive --format=zip -o "$OUTPUT_FILE" HEAD -- postishipping/

echo ""
echo "Created: $OUTPUT_FILE"
