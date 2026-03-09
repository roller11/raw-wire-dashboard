#!/bin/bash
#
# Setup script for LADBS Permit Scraper
# Installs Python dependencies and Playwright browsers
#

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

echo "=== LADBS Permit Scraper Setup ==="
echo ""

# Check Python
if ! command -v python3 &> /dev/null; then
    echo "ERROR: Python 3 is required but not installed."
    echo "Install with: sudo apt install python3"
    exit 1
fi

echo "Python 3 found: $(python3 --version)"

# Check pip
if ! python3 -m pip --version &> /dev/null; then
    echo "pip not found. Installing via ensurepip..."
    python3 -m ensurepip --upgrade 2>/dev/null || {
        echo "ERROR: pip is not available."
        echo "Install with: sudo apt install python3-pip"
        exit 1
    }
fi

echo "pip found: $(python3 -m pip --version)"

# Create virtual environment
VENV_DIR="$SCRIPT_DIR/venv"
if [ ! -d "$VENV_DIR" ]; then
    echo ""
    echo "Creating virtual environment in $VENV_DIR..."
    python3 -m venv "$VENV_DIR"
fi

# Activate venv
source "$VENV_DIR/bin/activate"
echo "Activated virtual environment"

# Install requirements
echo ""
echo "Installing Python packages..."
pip install --upgrade pip
pip install playwright mysql-connector-python

# Install Playwright browsers
echo ""
echo "Installing Playwright Chromium browser..."
playwright install chromium

echo ""
echo "=== Setup Complete ==="
echo ""
echo "The scraper is ready to use. You can run it with:"
echo ""
echo "  # Using venv directly:"
echo "  $VENV_DIR/bin/python $SCRIPT_DIR/ladbs_scraper.py --permit 23010-20000-01607"
echo ""
echo "  # Or from WordPress CLI:"
echo "  wp rawwire pipeline run --limit=5"
echo ""
echo "  # Check pipeline status:"
echo "  wp rawwire pipeline status"
