# Packaging source

This directory is the source root for the final Joomla package. No binary ZIP
files are committed.

From the repository root, generate the installable archive with:

~~~powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\build-package.ps1
~~~

The script creates dist/pkg_memipilates-1.6.1.zip, prints its SHA-256, and
builds all child extension archives in an isolated temporary directory.

At release time, build these child archives from the source directories:

- packages/com_memipilates to package/packages/com_memipilates.zip
- packages/plg_task_memipilates to package/packages/plg_task_memipilates.zip
- packages/file_memipilates_cli to package/packages/file_memipilates_cli.zip

Then package pkg_memipilates.xml, language/, and packages/ into the
installable package archive. Keep the child archive names unchanged:
they are referenced by pkg_memipilates.xml.
