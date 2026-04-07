# Fix: B2 SignatureDoesNotMatch en test conexion (ListObjects)

- Date: 2026-02-08 23:14

## Context

En staging el test B2 devolvia 403 SignatureDoesNotMatch. Se ajusto el cliente S3 para firmar operaciones bucket-level (ListObjectsV2) con path-style `/<bucket>` (sin slash final) y para usar URL consistente (`.../<bucket>?list-type=2...`). Se subio la version del plugin a 1.2.2 para rollout. Gates verdes con composer release:verify.

## What worked

El cambio es minimo dentro de zona protegida (s3-client) y alinea el path con la forma estandar de S3 para ListObjects. B2 ahora deberia validar firma si el par KeyID/ApplicationKey es correcto. Release gate paso.

## What failed

Nada relevante.

## Next time

Agregar un test vector de AWS SigV4 para ListObjects (path-style) y/o un modo de diagnostico admin que muestre solo fingerprints (hash) del KeyID/AppKey leidos para evitar confusion por campos write-only.
