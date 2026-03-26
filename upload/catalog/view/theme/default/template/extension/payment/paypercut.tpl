<div class="paypercut-payment">
  <!-- Payment Methods Display -->
  <?php if ($payment_methods) { ?>
  <div class="payment-methods-display" style="margin-bottom: 20px; padding: 15px; background: #f9f9f9; border-radius: 5px;">
    <h4 style="margin-top: 0; font-size: 14px; color: #666;">
      <i class="fa fa-lock"></i> <?php echo $text_secure_payment; ?>
    </h4>
    <div class="payment-methods-list" style="display: flex; flex-wrap: wrap; gap: 10px;">
      <?php foreach ($payment_methods as $method) { ?>
      <div class="payment-method-item" style="display: flex; align-items: center; padding: 8px 12px; background: white; border: 1px solid #ddd; border-radius: 4px;">
        <i class="fa <?php echo $method['icon']; ?>" style="margin-right: 8px; font-size: 18px; color: #333;"></i>
        <span style="font-size: 13px; color: #555;"><?php echo $method['name']; ?></span>
      </div>
      <?php } ?>
    </div>
    <p style="margin: 10px 0 0 0; font-size: 11px; color: #999;">
      <i class="fa fa-shield"></i> Your payment information is encrypted and secure
    </p>
  </div>
  <?php } ?>
  
  <!-- Error Message Container -->
  <div id="payment-error-container" style="display: none; margin-bottom: 15px;">
    <div class="alert alert-danger alert-dismissible">
      <button type="button" class="close" data-dismiss="alert">&times;</button>
      <i class="fa fa-exclamation-circle"></i> <span id="payment-error-message"></span>
    </div>
  </div>
  
  <!-- Embedded Checkout Container -->
  <?php if (isset($checkout_mode) && $checkout_mode === 'embedded') { ?>
  <div id="paypercut-embedded-checkout" style="margin-bottom: 15px; height: 700px"></div>
  <?php } ?>
  
  <!-- Confirm Button -->
  <div class="buttons">
    <div class="pull-right">
      <?php if (isset($checkout_mode) && $checkout_mode === 'embedded') { ?>
      <input type="button" value="<?php echo $button_confirm; ?>" id="button-confirm" class="btn btn-primary" style="display: none;" />
      <?php } else { ?>
      <input type="button" value="<?php echo $button_confirm; ?>" id="button-confirm" class="btn btn-primary" />
      <?php } ?>
    </div>
  </div>
</div>

<!-- Paypercut Checkout JavaScript SDK -->
<script src="https://cdn.jsdelivr.net/npm/@paypercut/checkout-js@1.0.12/dist/paypercut-checkout.iife.min.js"></script>

<script type="text/javascript"><!--
var paymentProcessing = false;
var paypercutCheckout = null;

<?php if (isset($checkout_mode) && $checkout_mode === 'embedded') { ?>
// Embedded mode - Initialize checkout on page load
<?php if (isset($checkout_id)) { ?>
// Wait for SDK to load
function initPaypercutCheckout() {
    try {
        paypercutCheckout = window.PaypercutCheckout({
            id: '<?php echo $checkout_id; ?>',
            containerId: '#paypercut-embedded-checkout',
            wallet_options: <?php echo json_encode(isset($wallet_options) ? $wallet_options : array()); ?>
        });

        paypercutCheckout.on('success', function(result) {
            // Log the result for debugging
            console.log('Payment success result:', result);
            
            // Redirect to callback with checkout ID (from template variable)
            window.location.href = 'index.php?route=extension/payment/paypercut/callback&checkout_id=<?php echo urlencode($checkout_id); ?>';
        });

        paypercutCheckout.on('error', function(error) {
            $('#payment-error-message').text(error.message || 'Payment failed. Please try again.');
            $('#payment-error-container').fadeIn();
            
            // Scroll to error
            $('html, body').animate({
                scrollTop: $('#payment-error-container').offset().top - 100
            }, 500);
        });

        paypercutCheckout.on('cancel', function() {
            // User cancelled - could redirect back to checkout
            console.log('Payment cancelled by user');
        });

        // Render the checkout form
        paypercutCheckout.render();
    } catch (error) {
        console.error('Embedded checkout initialization error:', error);
        $('#payment-error-message').text('Failed to initialize payment form. Please refresh the page and try again.');
        $('#payment-error-container').fadeIn();
    }
}

// Check if SDK is already loaded, otherwise wait for it
if (typeof window.PaypercutCheckout !== 'undefined') {
    $(document).ready(initPaypercutCheckout);
} else {
    // Wait for SDK script to load
    var checkSDK = setInterval(function() {
        if (typeof window.PaypercutCheckout !== 'undefined') {
            clearInterval(checkSDK);
            $(document).ready(initPaypercutCheckout);
        }
    }, 100);
    
    // Timeout after 10 seconds
    setTimeout(function() {
        if (typeof window.PaypercutCheckout === 'undefined') {
            clearInterval(checkSDK);
            $('#payment-error-message').text('Failed to load payment SDK. Please refresh the page.');
            $('#payment-error-container').fadeIn();
        }
    }, 10000);
}
<?php } else { ?>
// Error initializing checkout
$(document).ready(function() {
    $('#payment-error-message').text('<?php echo isset($checkout_error) ? $checkout_error : "Failed to initialize payment form"; ?>');
    $('#payment-error-container').fadeIn();
});
<?php } ?>

<?php } else { ?>
// Hosted mode - Create checkout on button click
$('#button-confirm').on('click', function() {
    // Prevent double submission
    if (paymentProcessing) {
        return;
    }
    
    paymentProcessing = true;
    $('#payment-error-container').hide();
    
    $.ajax({
        type: 'get',
        url: 'index.php?route=extension/payment/paypercut/send',
        cache: false,
        dataType: 'json',
        beforeSend: function() {
            $('#button-confirm')
                .prop('disabled', true)
                .html('<i class="fa fa-spinner fa-spin"></i> <?php echo $text_loading; ?>');
        },
        success: function(json) {
            if (json['error']) {
                // Display error message
                $('#payment-error-message').text(json['error']);
                $('#payment-error-container').fadeIn();
                
                // Re-enable button
                $('#button-confirm')
                    .prop('disabled', false)
                    .val('<?php echo $button_confirm; ?>');
                    
                paymentProcessing = false;
                
                // Scroll to error
                $('html, body').animate({
                    scrollTop: $('#payment-error-container').offset().top - 100
                }, 500);
            } else if (json['redirect']) {
                // Redirect to hosted payment page
                location = json['redirect'];
            } else {
                // Unknown response
                $('#payment-error-message').text('An unexpected error occurred. Please try again.');
                $('#payment-error-container').fadeIn();
                $('#button-confirm')
                    .prop('disabled', false)
                    .val('<?php echo $button_confirm; ?>');
                paymentProcessing = false;
            }
        },
        error: function(xhr, ajaxOptions, thrownError) {
            var errorMsg = 'Connection error. Please check your internet connection and try again.';
            
            if (xhr.status === 408 || thrownError === 'timeout') {
                errorMsg = 'Request timed out. Please try again.';
            } else if (xhr.status === 500) {
                errorMsg = 'Server error. Please try again or contact support.';
            } else if (xhr.status === 0) {
                errorMsg = 'No connection. Please check your internet and try again.';
            }
            
            $('#payment-error-message').text(errorMsg);
            $('#payment-error-container').fadeIn();
            
            $('#button-confirm')
                .prop('disabled', false)
                .val('<?php echo $button_confirm; ?>');
                
            paymentProcessing = false;
            
            // Log error for debugging
            console.error('Payment Error:', {
                status: xhr.status,
                error: thrownError,
                response: xhr.responseText
            });
        },
        timeout: 30000 // 30 seconds timeout
    });
});
<?php } ?>
//--></script>
