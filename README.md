# Fulfillment Service
# (Acenda Fulfillment Service)

![enter image description here](https://acenda.com/images/logo-acenda@2x.png)

----------

## Description

This service allows a user to schedule files to be downloaded and to be processed for the fulfillment of orders

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
