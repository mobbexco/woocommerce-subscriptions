#!/bin/sh

VER="3.3.5"

# Copy files to temp dir
if type robocopy > /dev/null; then
    robocopy . woocommerce-mobbex-subs -MIR -XD .git .vscode .github woocommerce-mobbex-subs vendor -XF .gitignore build.sh readme.md *.zip
elif type rsync > /dev/null; then
    rsync -r --exclude={'.git','.vscode','.github','woocommerce-mobbex-subs','vendor','.gitignore','build.sh','readme.md','*.zip'} . ./woocommerce-mobbex-subs
fi

# Compress
if type 7z > /dev/null; then
    7z a -tzip "wcs-mobbex.$VER.zip" woocommerce-mobbex-subs
elif type zip > /dev/null; then
    zip wcs-mobbex.$VER.zip -r woocommerce-mobbex-subs
fi

# Remove temp dir
rm -r ./woocommerce-mobbex-subs