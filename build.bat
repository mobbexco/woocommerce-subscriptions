set ver="2.3.0"

:: Create directory with plugin files
robocopy . woocommerce-mobbex-subs /MIR /XD .git .vscode woocommerce-mobbex-subs /XF .gitignore build.bat readme.md *.zip ignorethis*

:: Compress archive
7z a -tzip wcs-mobbex.%ver%.zip woocommerce-mobbex-subs

:: Delete directory
rd /s /q woocommerce-mobbex-subs