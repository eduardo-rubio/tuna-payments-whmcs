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
| transid           | operationId o paymentKey? |
| gatewayid         | token / cardInfo.token |


![Tuna!](whmcs/modules/gateways/tunapayment/tuna.png)