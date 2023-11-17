#  Tuna Payment Gateway Module for WHMCS #

## Summary ##

Payment Gateway modules allow you to integrate Tuna payment gateway with the WHMCS
platform.

| WMHCS      | Tuna |
| ----------- | ----------- |
| clientdetails.id  | customer.id       |
| invoiceid         | detailUniqueId / partnerUniqueId       |
| "Tuna"            | tokenProvider |
| "Blymp"           | softDescriptor |
| cardissuenum      | cardNumber |
| invoiceid         | partnerUniqueId |
| transid           | paymentKey |
| gatewayid         | token / cardInfo.token |

Configure Custom Fields
Two custom fields must be configured: documenttype and documentnumber

https://docs.whmcs.com/Custom_Fields

![](doc/WHMCSCustomFields.png)
![](doc/WHMCSCustomFieldsInput.png)


![Tuna!](whmcs/modules/gateways/tunapayment/tuna.png)