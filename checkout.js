
const settings = window.wc.wcSettings.getSetting( 'my_custom_gateway_data', {} );
const label = window.wp.htmlEntities.decodeEntities(wfp_title) || window.wp.i18n.__( 'Pay with Visa/Mastercard via Wayforpay', 'woocommerce-wayforpay-payments');
const Content = () => {
    return window.wp.htmlEntities.decodeEntities(
        wfp_description || window.wp.i18n.__('Pay securely by Credit or Debit Card or Internet Banking through wayforpay.com service.', 'woocommerce-wayforpay-payments')
    );
};
const Block_Gateway = {
    name: 'wayforpay',
    label: label,
    content: Object( window.wp.element.createElement )( Content, null ),
    edit: Object( window.wp.element.createElement )( Content, null ),
    canMakePayment: () => true,
    ariaLabel: label,
};
window.wc.wcBlocksRegistry.registerPaymentMethod(Block_Gateway);
