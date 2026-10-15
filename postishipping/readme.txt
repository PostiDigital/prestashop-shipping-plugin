Posti Shipping Module
=====================

Email Pickup Point Variables
----------------------------

The module provides template variables that display the selected pickup point
in order-related emails (order confirmation, shipping notification, etc.).

Available placeholders:

    {postishipping_pickup_point}
        Pickup point address only.
        Output example: "Posti SmartPOST, Mannerheimintie 1, 00100 Helsinki"
        Empty string when no pickup point is associated with the order.

    {postishipping_pickup_point_title}
        Includes the "Pickup point:" label together with the address.
        Output example: "Pickup point: Posti SmartPOST, Mannerheimintie 1, 00100 Helsinki"
        Empty string when no pickup point is associated with the order.
        Use this when you want the entire line (label + address) to be hidden
        for orders that have no pickup point or use a different shipping method.

Example usage in an email template (e.g. order_conf.html):

    Using the title variable (recommended — the line is completely hidden when
    no pickup point exists):

        <p>{postishipping_pickup_point_title}</p>

    Using the address-only variable with your own label:

        <p>Your pickup point: {postishipping_pickup_point}</p>

