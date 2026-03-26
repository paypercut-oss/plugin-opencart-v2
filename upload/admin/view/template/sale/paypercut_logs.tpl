<?php echo $header; ?><?php echo $column_left; ?>
<div id="content">
  <div class="page-header">
    <div class="container-fluid">
      <h1>Paypercut Logs</h1>
      <ul class="breadcrumb">
        <?php foreach ($breadcrumbs as $breadcrumb) { ?>
        <li><a href="<?php echo $breadcrumb['href']; ?>"><?php echo $breadcrumb['text']; ?></a></li>
        <?php } ?>
      </ul>
    </div>
  </div>
  <div class="container-fluid">
    
    <!-- Tabs -->
    <ul class="nav nav-tabs" role="tablist">
      <li class="active"><a href="#tab-webhook-logs" data-toggle="tab"><i class="fa fa-exchange"></i> Webhook Logs</a></li>
      <li><a href="#tab-error-log" data-toggle="tab"><i class="fa fa-exclamation-triangle"></i> Error Log</a></li>
    </ul>
    
    <div class="tab-content">
      
      <!-- Webhook Logs Tab -->
      <div class="tab-pane active" id="tab-webhook-logs">
        <div class="panel panel-default">
          <div class="panel-heading">
            <h3 class="panel-title">
              <i class="fa fa-exchange"></i> Webhook Event Logs
              <button type="button" id="btn-clear-webhook" class="btn btn-danger btn-xs pull-right">
                <i class="fa fa-trash"></i> Clear Logs
              </button>
            </h3>
          </div>
          <div class="panel-body">
            
            <!-- Filters -->
            <form method="get" class="form-inline" style="margin-bottom: 15px;">
              <input type="hidden" name="route" value="sale/paypercut_logs">
              <input type="hidden" name="token" value="<?php echo $token; ?>">
              
              <div class="form-group">
                <label>Event Type:</label>
                <select name="filter_type" class="form-control">
                  <option value="">All Events</option>
                  <option value="payment.succeeded" <?php if ($filter_type == 'payment.succeeded') { ?>selected<?php } ?>>payment.succeeded</option>
                  <option value="payment.failed" <?php if ($filter_type == 'payment.failed') { ?>selected<?php } ?>>payment.failed</option>
                  <option value="payment.pending" <?php if ($filter_type == 'payment.pending') { ?>selected<?php } ?>>payment.pending</option>
                  <option value="refund.succeeded" <?php if ($filter_type == 'refund.succeeded') { ?>selected<?php } ?>>refund.succeeded</option>
                  <option value="refund.failed" <?php if ($filter_type == 'refund.failed') { ?>selected<?php } ?>>refund.failed</option>
                </select>
              </div>
              
              <div class="form-group">
                <label>Date From:</label>
                <input type="date" name="filter_date_start" value="<?php echo $filter_date_start; ?>" class="form-control">
              </div>
              
              <div class="form-group">
                <label>Date To:</label>
                <input type="date" name="filter_date_end" value="<?php echo $filter_date_end; ?>" class="form-control">
              </div>
              
              <button type="submit" class="btn btn-primary">
                <i class="fa fa-filter"></i> Filter
              </button>
              
              <a href="index.php?route=sale/paypercut_logs&token=<?php echo $token; ?>" class="btn btn-default">
                <i class="fa fa-refresh"></i> Reset
              </a>
            </form>
            
            <!-- Logs Table -->
            <div class="table-responsive">
              <table class="table table-bordered table-hover">
                <thead>
                  <tr>
                    <th>Date/Time</th>
                    <th>Event Type</th>
                    <th>Event ID</th>
                    <th>Processed</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($webhook_logs) { ?>
                    <?php foreach ($webhook_logs as $log) { ?>
                    <tr>
                      <td><?php echo $log['created_at']; ?></td>
                      <td>
                        <span class="label label-info"><?php echo $log['event_type']; ?></span>
                      </td>
                      <td><code><?php echo $log['event_id']; ?></code></td>
                      <td>
                        <?php if ($log['processed'] == 1) { ?>
                          <span class="label label-success"><i class="fa fa-check"></i> Yes</span>
                        <?php } else { ?>
                          <span class="label label-warning"><i class="fa fa-clock-o"></i> No</span>
                        <?php } ?>
                      </td>
                      <td>
                        <button type="button" class="btn btn-xs btn-info view-payload" data-payload="<?php echo htmlspecialchars($log['payload_formatted'], ENT_QUOTES, 'UTF-8'); ?>">
                          <i class="fa fa-eye"></i> View Payload
                        </button>
                      </td>
                    </tr>
                    <?php } ?>
                  <?php } else { ?>
                    <tr>
                      <td colspan="5" class="text-center">No webhook logs found</td>
                    </tr>
                  <?php } ?>
                </tbody>
              </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($pagination) { ?>
            <div class="row">
              <div class="col-sm-6 text-left">
                Showing <?php echo count($webhook_logs); ?> of <?php echo $webhook_total; ?> logs
              </div>
              <div class="col-sm-6 text-right">
                <?php echo $pagination; ?>
              </div>
            </div>
            <?php } ?>
            
          </div>
        </div>
      </div>
      
      <!-- Error Log Tab -->
      <div class="tab-pane" id="tab-error-log">
        <div class="panel panel-default">
          <div class="panel-heading">
            <h3 class="panel-title">
              <i class="fa fa-exclamation-triangle"></i> Error Log (paypercut_error.log)
              <button type="button" id="btn-clear-error" class="btn btn-danger btn-xs pull-right">
                <i class="fa fa-trash"></i> Clear Log
              </button>
            </h3>
          </div>
          <div class="panel-body">
            <pre id="error-log-content" style="max-height: 600px; overflow-y: auto; background-color: #f5f5f5; padding: 15px; border: 1px solid #ddd;"><?php echo $error_log; ?></pre>
          </div>
        </div>
      </div>
      
    </div>
  </div>
</div>

<!-- Payload Modal -->
<div class="modal fade" id="payload-modal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
        <h4 class="modal-title">
          <i class="fa fa-code"></i> Webhook Payload
        </h4>
      </div>
      <div class="modal-body">
        <pre id="payload-content" style="max-height: 500px; overflow-y: auto;"></pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script type="text/javascript">
// View webhook payload
$('.view-payload').on('click', function() {
    var payload = $(this).data('payload');
    $('#payload-content').text(payload);
    $('#payload-modal').modal('show');
});

// Clear webhook logs
$('#btn-clear-webhook').on('click', function() {
    if (!confirm('Are you sure you want to clear all webhook logs? This action cannot be undone.')) {
        return;
    }
    
    $.ajax({
        url: 'index.php?route=sale/paypercut_logs/clearWebhookLogs&token=<?php echo $token; ?>',
        type: 'post',
        dataType: 'json',
        success: function(json) {
            if (json.success) {
                alert(json.success);
                location.reload();
            } else if (json.error) {
                alert(json.error);
            }
        }
    });
});

// Clear error log
$('#btn-clear-error').on('click', function() {
    if (!confirm('Are you sure you want to clear the error log? This action cannot be undone.')) {
        return;
    }
    
    $.ajax({
        url: 'index.php?route=sale/paypercut_logs/clearErrorLog&token=<?php echo $token; ?>',
        type: 'post',
        dataType: 'json',
        success: function(json) {
            if (json.success) {
                alert(json.success);
                $('#error-log-content').text('No error log found.');
            } else if (json.error) {
                alert(json.error);
            }
        }
    });
});
</script>

<?php echo $footer; ?>
