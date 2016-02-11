# Recurring Imports
# (Acenda URL Based Import Service)

![enter image description here](https://acenda.com/images/logo-acenda@2x.png)

----------

## Description

This service allows a user to schedule an import or an update from a URL.

As of now, the file can either be a CSV (Comma separated file), a ZIP (Compressed file) or a GZIP (Compressed file).

> **Note:**
  * This service is exclusively made for Acenda *


--------

## JSON Credentials Expected

```json
{
   "file_url": {
       "type": "string",
       "label": "File URL"
   },
   "import_type": {
       "label": "Import type",
       "type": "select",
       "values": [
           "Inventory",
           "Variant",
           "Product"
       ]
   },
   "match": {
       "label": "Match",
       "type": "string"
   }
}
```

--------

## No Schema Data Expected.


![enter image description here](https://acenda.com/images/logo-acenda@2x.png)
