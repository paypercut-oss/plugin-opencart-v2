<?php if ($has_transaction) { ?>
<div class="panel panel-default">
  <div class="panel-heading">
    <h3 class="panel-title">
      <i class="fa fa-credit-card"></i> Paypercut Payment Information
    </h3>
  </div>
  <div class="panel-body">
    
    <!-- Transaction Details -->
    <table class="table table-bordered">
      <tbody>
        <tr>
          <td style="width: 30%;"><strong>Transaction ID</strong></td>
          <td>
            <code><?php echo $transaction['payment_id']; ?></code>
            <a href="<?php echo $paypercut_dashboard_url; ?>" target="_blank" class="btn btn-xs btn-info" style="margin-left: 10px;">
              <i class="fa fa-external-link"></i> View in Dashboard
            </a>
          </td>
        </tr>
        <tr>
          <td><strong>Payment Status</strong></td>
          <td>
            <?php if ($transaction['status'] == 'succeeded') { ?>
              <span class="label label-success"><i class="fa fa-check-circle"></i> Succeeded</span>
            <?php } elseif ($transaction['status'] == 'pending') { ?>
              <span class="label label-warning"><i class="fa fa-clock-o"></i> Pending</span>
            <?php } elseif ($transaction['status'] == 'failed') { ?>
              <span class="label label-danger"><i class="fa fa-times-circle"></i> Failed</span>
            <?php } else { ?>
              <span class="label label-default"><?php echo ucfirst($transaction['status']); ?></span>
            <?php } ?>
          </td>
        </tr>
        <tr>
          <td><strong>Payment Method</strong></td>
          <td>
            <i class="fa fa-credit-card"></i> <?php echo $payment_method_formatted; ?>
          </td>
        </tr>
        <tr>
          <td><strong>Amount</strong></td>
          <td><strong><?php echo number_format($transaction['amount'], 2); ?> <?php echo $transaction['currency']; ?></strong></td>
        </tr>
        <?php if ($transaction['paypercut_customer_id']) { ?>
        <tr>
          <td><strong>Paypercut Customer ID</strong></td>
          <td><code><?php echo $transaction['paypercut_customer_id']; ?></code></td>
        </tr>
        <?php } ?>
        <tr>
          <td><strong>Payment Date</strong></td>
          <td><?php echo $transaction['created_at']; ?></td>
        </tr>
        <tr>
          <td colspan="2">
            <button type="button" id="btn-view-details" class="btn btn-sm btn-default">
              <i class="fa fa-info-circle"></i> View Full Transaction Details
            </button>
          </td>
        </tr>
      </tbody>
    </table>
    
    <!-- Payment Actions for Uncaptured Payments -->
    <?php if ($transaction['status'] == 'requires_capture') { ?>
    <hr>
    <div class="alert alert-warning">
      <i class="fa fa-exclamation-triangle"></i> <strong>Payment Authorization:</strong> This payment has been authorized but not yet captured. You must capture or cancel it.
    </div>
    
    <div class="btn-group" role="group">
      <button type="button" id="btn-capture-payment" class="btn btn-success">
        <i class="fa fa-check"></i> Capture Payment
      </button>
      <button type="button" id="btn-cancel-payment" class="btn btn-danger">
        <i class="fa fa-ban"></i> Cancel Payment
      </button>
    </div>
    
    <div id="payment-action-status" style="margin-top: 15px;"></div>
    <?php } ?>
    
    <!-- Refund Section -->
    <?php if ($transaction['status'] == 'succeeded') { ?>
    <hr>
    <h4><i class="fa fa-undo"></i> Refund Management</h4>
    
    <?php if ($refunds) { ?>
    <div class="alert alert-info">
      <strong>Refund History:</strong>
      <ul style="margin: 10px 0 0 0; padding-left: 20px;">
        <?php foreach ($refunds as $refund) { ?>
        <li>
          <strong><?php echo number_format($refund['amount'], 2); ?> <?php echo $refund['currency']; ?></strong>
          - <code><?php echo $refund['refund_id']; ?></code>
          - 
          <?php if ($refund['status'] == 'succeeded') { ?>
            <span class="label label-success">Succeeded</span>
          <?php } elseif ($refund['status'] == 'pending') { ?>
            <span class="label label-warning">Pending</span>
          <?php } elseif ($refund['status'] == 'failed') { ?>
            <span class="label label-danger">Failed</span>
          <?php } ?>
          <br>
          <small><?php echo $refund['created_at']; ?><?php if ($refund['reason']) { ?> - <?php echo $refund['reason']; ?><?php } ?></small>
        </li>
        <?php } ?>
      </ul>
      <p style="margin-top: 10px;"><strong>Total Refunded:</strong> <?php echo number_format($total_refunded, 2); ?> <?php echo $transaction['currency']; ?></p>
    </div>
    <?php } ?>
    
    <?php if ($can_refund) { ?>
    <div id="refund-form">
      <div class="form-group">
        <label class="control-label">Refund Type:</label>
        <div>
          <label class="radio-inline">
            <input type="radio" name="refund_type" value="partial" id="refund-partial" checked> Partial Refund
          </label>
          <label class="radio-inline">
            <input type="radio" name="refund_type" value="full" id="refund-full"> Full Refund
          </label>
        </div>
      </div>
      
      <div class="form-group" id="refund-amount-group">
        <label class="control-label" for="refund-amount">Refund Amount (<?php echo $transaction['currency']; ?>):</label>
        <input type="number" class="form-control" id="refund-amount" step="0.01" min="0.01" max="<?php echo $transaction['amount'] - $total_refunded; ?>" placeholder="0.00" style="max-width: 200px;">
        <span class="help-block">Maximum refundable: <?php echo number_format($transaction['amount'] - $total_refunded, 2); ?> <?php echo $transaction['currency']; ?></span>
      </div>
      
      <div class="form-group">
        <label class="control-label" for="refund-reason">Reason:</label>
        <select class="form-control" id="refund-reason" style="max-width: 300px;">
          <option value="requested_by_customer">Requested by Customer</option>
          <option value="duplicate">Duplicate Payment</option>
          <option value="fraudulent">Fraudulent Transaction</option>
        </select>
        <span class="help-block">Select the reason for this refund</span>
      </div>
      
      <div id="refund-status"></div>
      
      <button type="button" id="btn-process-refund" class="btn btn-danger">
        <i class="fa fa-undo"></i> Process Refund
      </button>
    </div>
    <?php } else { ?>
      <?php if ($total_refunded >= $transaction['amount']) { ?>
      <div class="alert alert-warning">
        <i class="fa fa-info-circle"></i> This payment has been fully refunded.
      </div>
      <?php } ?>
    <?php } ?>
    <?php } ?>
    
  </div>
</div>

<!-- Transaction Details Modal -->
<div class="modal fade" id="transaction-details-modal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
        <h4 class="modal-title">
          <i class="fa fa-info-circle"></i> Full Transaction Details
        </h4>
      </div>
      <div class="modal-body" id="transaction-details-content">
        <!-- Content loaded via AJAX -->
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script type="text/javascript">
// Handle refund type toggle
$('input[name="refund_type"]').on('change', function() {
    if ($(this).val() === 'full') {
        $('#refund-amount-group').hide();
    } else {
        $('#refund-amount-group').show();
    }
});

// Process refund
$('#btn-process-refund').on('click', function() {
    var refundType = $('input[name="refund_type"]:checked').val();
    var refundAmount = parseFloat($('#refund-amount').val());
    var refundReason = $('#refund-reason').val();
    var isFull = refundType === 'full';
    
    // Validation
    if (!isFull && (!refundAmount || refundAmount <= 0)) {
        alert('Please enter a valid refund amount.');
        return;
    }
    
    if (!confirm('Are you sure you want to process this refund? This action cannot be undone.')) {
        return;
    }
    
    $('#refund-status').html('<div class="alert alert-info"><i class="fa fa-spinner fa-spin"></i> Processing refund...</div>');
    $('#btn-process-refund').prop('disabled', true);
    
    $.ajax({
        url: 'index.php?route=sale/paypercut_order/refund&token=<?php echo $token; ?>',
        type: 'post',
        data: {
            order_id: <?php echo $order_id; ?>,
            refund_amount: refundAmount,
            refund_reason: refundReason,
            full_refund: isFull
        },
        dataType: 'json',
        success: function(json) {
            if (json.error) {
                $('#refund-status').html(
                    '<div class="alert alert-danger alert-dismissible">' +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '<i class="fa fa-exclamation-circle"></i> <strong>Error:</strong> ' + json.error +
                    '</div>'
                );
                $('#btn-process-refund').prop('disabled', false);
            } else if (json.success) {
                $('#refund-status').html(
                    '<div class="alert alert-success alert-dismissible">' +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '<i class="fa fa-check-circle"></i> <strong>Success:</strong> ' + json.success +
                    '<br><small>Refund ID: ' + json.refund_id + ' | Amount: ' + json.amount + ' <?php echo $transaction['currency']; ?></small>' +
                    '</div>'
                );
                
                // Reload page after 2 seconds to show updated refund history
                setTimeout(function() {
                    location.reload();
                }, 2000);
            }
        },
        error: function(xhr, status, error) {
            $('#refund-status').html(
                '<div class="alert alert-danger alert-dismissible">' +
                '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                '<i class="fa fa-exclamation-triangle"></i> <strong>Connection Error:</strong> ' + error +
                '</div>'
            );
            $('#btn-process-refund').prop('disabled', false);
        }
    });
});

// View transaction details
$('#btn-view-details').on('click', function() {
    $('#transaction-details-modal').modal('show');
    $('#transaction-details-content').html('<div class="text-center"><i class="fa fa-spinner fa-spin fa-3x"></i><p>Loading...</p></div>');
    
    $.ajax({
        url: 'index.php?route=sale/paypercut_order/getTransactionDetails&token=<?php echo $token; ?>&order_id=<?php echo $order_id; ?>',
        type: 'get',
        dataType: 'json',
        success: function(json) {
            if (json.error) {
                $('#transaction-details-content').html(
                    '<div class="alert alert-danger">' +
                    '<i class="fa fa-exclamation-circle"></i> ' + json.error +
                    '</div>'
                );
            } else if (json.success) {
                var html = '<table class="table table-bordered table-striped">';
                
                // Basic info
                html += '<tr><th colspan="2" class="bg-info">Payment Information</th></tr>';
                html += '<tr><td style="width: 30%;"><strong>Payment ID</strong></td><td><code>' + json.payment.id + '</code></td></tr>';
                html += '<tr><td><strong>Status</strong></td><td>' + json.payment.status + '</td></tr>';
                html += '<tr><td><strong>Amount</strong></td><td>' + json.amount.toFixed(2) + ' ' + json.currency.toUpperCase() + '</td></tr>';
                
                // Fees and net amount
                if (json.fees !== undefined) {
                    html += '<tr><td><strong>Fees</strong></td><td>' + json.fees.toFixed(2) + ' ' + json.currency.toUpperCase() + '</td></tr>';
                    html += '<tr><td><strong>Net Amount</strong></td><td><strong>' + json.net_amount.toFixed(2) + ' ' + json.currency.toUpperCase() + '</strong></td></tr>';
                }
                
                // Capture status
                html += '<tr><td><strong>Captured</strong></td><td>' + (json.captured ? '<span class="label label-success">Yes</span>' : '<span class="label label-warning">No</span>') + '</td></tr>';
                
                if (json.capture_before) {
                    var captureDate = new Date(json.capture_before * 1000);
                    html += '<tr><td><strong>Capture Before</strong></td><td>' + captureDate.toLocaleString() + '</td></tr>';
                }
                
                // 3DS authentication
                if (json.three_d_secure) {
                    html += '<tr><th colspan="2" class="bg-info">3D Secure Authentication</th></tr>';
                    html += '<tr><td><strong>Authenticated</strong></td><td>' + (json.three_d_secure.authenticated ? '<span class="label label-success">Yes</span>' : '<span class="label label-warning">No</span>') + '</td></tr>';
                    
                    if (json.three_d_secure.version) {
                        html += '<tr><td><strong>3DS Version</strong></td><td>' + json.three_d_secure.version + '</td></tr>';
                    }
                    
                    if (json.three_d_secure.result) {
                        html += '<tr><td><strong>Result</strong></td><td>' + json.three_d_secure.result + '</td></tr>';
                    }
                }
                
                // Payment method details
                if (json.payment.payment_method) {
                    html += '<tr><th colspan="2" class="bg-info">Payment Method Details</th></tr>';
                    html += '<tr><td colspan="2"><pre>' + JSON.stringify(json.payment.payment_method, null, 2) + '</pre></td></tr>';
                }
                
                html += '</table>';
                
                $('#transaction-details-content').html(html);
            }
        },
        error: function() {
            $('#transaction-details-content').html(
                '<div class="alert alert-danger">' +
                '<i class="fa fa-exclamation-triangle"></i> Connection error' +
                '</div>'
            );
        }
    });
});

// Capture payment
$('#btn-capture-payment').on('click', function() {
    if (!confirm('Are you sure you want to capture this payment?')) {
        return;
    }
    
    $('#payment-action-status').html('<div class="alert alert-info"><i class="fa fa-spinner fa-spin"></i> Capturing payment...</div>');
    $('#btn-capture-payment').prop('disabled', true);
    $('#btn-cancel-payment').prop('disabled', true);
    
    $.ajax({
        url: 'index.php?route=sale/paypercut_order/capture&token=<?php echo $token; ?>',
        type: 'post',
        data: {
            order_id: <?php echo $order_id; ?>,
            capture_amount: 0 // Full amount
        },
        dataType: 'json',
        success: function(json) {
            if (json.error) {
                $('#payment-action-status').html(
                    '<div class="alert alert-danger alert-dismissible">' +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '<i class="fa fa-exclamation-circle"></i> <strong>Error:</strong> ' + json.error +
                    '</div>'
                );
                $('#btn-capture-payment').prop('disabled', false);
                $('#btn-cancel-payment').prop('disabled', false);
            } else if (json.success) {
                $('#payment-action-status').html(
                    '<div class="alert alert-success alert-dismissible">' +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '<i class="fa fa-check-circle"></i> <strong>Success:</strong> ' + json.success +
                    '</div>'
                );
                
                setTimeout(function() {
                    location.reload();
                }, 2000);
            }
        },
        error: function() {
            $('#payment-action-status').html(
                '<div class="alert alert-danger">' +
                '<i class="fa fa-exclamation-triangle"></i> Connection error' +
                '</div>'
            );
            $('#btn-capture-payment').prop('disabled', false);
            $('#btn-cancel-payment').prop('disabled', false);
        }
    });
});

// Cancel payment
$('#btn-cancel-payment').on('click', function() {
    var reason = prompt('Please enter a reason for canceling this payment (optional):');
    
    if (reason === null) {
        return; // User clicked cancel
    }
    
    if (!confirm('Are you sure you want to cancel this payment? This action cannot be undone.')) {
        return;
    }
    
    $('#payment-action-status').html('<div class="alert alert-info"><i class="fa fa-spinner fa-spin"></i> Canceling payment...</div>');
    $('#btn-capture-payment').prop('disabled', true);
    $('#btn-cancel-payment').prop('disabled', true);
    
    $.ajax({
        url: 'index.php?route=sale/paypercut_order/cancel&token=<?php echo $token; ?>',
        type: 'post',
        data: {
            order_id: <?php echo $order_id; ?>,
            cancel_reason: reason
        },
        dataType: 'json',
        success: function(json) {
            if (json.error) {
                $('#payment-action-status').html(
                    '<div class="alert alert-danger alert-dismissible">' +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '<i class="fa fa-exclamation-circle"></i> <strong>Error:</strong> ' + json.error +
                    '</div>'
                );
                $('#btn-capture-payment').prop('disabled', false);
                $('#btn-cancel-payment').prop('disabled', false);
            } else if (json.success) {
                $('#payment-action-status').html(
                    '<div class="alert alert-success alert-dismissible">' +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '<i class="fa fa-check-circle"></i> <strong>Success:</strong> ' + json.success +
                    '</div>'
                );
                
                setTimeout(function() {
                    location.reload();
                }, 2000);
            }
        },
        error: function() {
            $('#payment-action-status').html(
                '<div class="alert alert-danger">' +
                '<i class="fa fa-exclamation-triangle"></i> Connection error' +
                '</div>'
            );
            $('#btn-capture-payment').prop('disabled', false);
            $('#btn-cancel-payment').prop('disabled', false);
        }
    });
});

</script>

<?php } else { ?>
<!-- No Paypercut transaction found for this order -->
<?php } ?>
