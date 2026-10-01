# omnibus/gls

GLS for [glitchr/omnibus](https://github.com/glitchr-studio/omnibus): shipments with their
labels and cancellations through the ShipIT web API, tracking through GLS's public parcel
tracking. Prices come from configuration (`rates`): ShipIT quotes none.

```yaml
omnibus:
    gateways:
        gls:
            factory: gls
            options:
                username: '%env(GLS_USERNAME)%'
                password: '%env(GLS_PASSWORD)%'
                contact_id: '%env(GLS_CONTACT_ID)%'
                sandbox: true
                rates:
                    - { service: PARCEL, label: 'GLS', amount: 690 }
```

The service is the product (PARCEL by default, EXPRESS, FREIGHT); a shipment with a
`pickupPoint` (a ParcelShop id) adds the ShopDelivery service; a recipient with an email gets
FlexDelivery (option `flex_delivery: false` to skip it). No ParcelShop search: GLS's finder is a
separate contract from ShipIT.

Credentials: a GLS customer account with ShipIT web API access (your GLS sales contact gives the
test login, the contact id and then the production login).

Built from GLS's published ShipIT documentation and tested on recorded answers; not yet run
against the test system: that needs the credentials above.

License: LGPL-3.0-or-later.
