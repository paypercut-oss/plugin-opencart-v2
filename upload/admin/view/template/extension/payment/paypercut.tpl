<?php echo $header; ?><?php echo $column_left; ?>
<div id="content">
  <div class="page-header">
    <div class="container-fluid">
      <div class="pull-right">
        <button type="submit" form="form-payment" data-toggle="tooltip" title="<?php echo $button_save; ?>" class="btn btn-primary"><i class="fa fa-save"></i></button>
        <a href="<?php echo $cancel; ?>" data-toggle="tooltip" title="<?php echo $button_cancel; ?>" class="btn btn-default"><i class="fa fa-reply"></i></a></div>
      <h1><?php echo $heading_title; ?></h1>
      <ul class="breadcrumb">
        <?php foreach ($breadcrumbs as $breadcrumb) { ?>
        <li><a href="<?php echo $breadcrumb['href']; ?>"><?php echo $breadcrumb['text']; ?></a></li>
        <?php } ?>
      </ul>
    </div>
  </div>
  <div class="container-fluid">
    <?php if ($error_warning) { ?>
    <div class="alert alert-danger alert-dismissible"><i class="fa fa-exclamation-circle"></i> <?php echo $error_warning; ?>
      <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
    <?php } ?>
    <div class="panel panel-default">
      <div class="panel-heading">
        <h3 class="panel-title"><i class="fa fa-pencil"></i> <?php echo $text_edit; ?></h3>
      </div>
      <div class="panel-body">
        <form action="<?php echo $action; ?>" method="post" enctype="multipart/form-data" id="form-payment" class="form-horizontal">
          
          <!-- Tab Navigation -->
          <ul class="nav nav-tabs" id="paypercut-tabs">
            <li class="active"><a href="#tab-api" data-toggle="tab"><i class="fa fa-key"></i> API Configuration</a></li>
            <li><a href="#tab-payment" data-toggle="tab"><i class="fa fa-credit-card"></i> Payment Settings</a></li>
            <li><a href="#tab-webhooks" data-toggle="tab"><i class="fa fa-bell"></i> Webhooks</a></li>
            <li><a href="#tab-general" data-toggle="tab"><i class="fa fa-cog"></i> General</a></li>
            <li><a href="#tab-diagnostics" data-toggle="tab"><i class="fa fa-stethoscope"></i> Diagnostics</a></li>
          </ul>
          
          <div class="tab-content">
            
            <!-- API Configuration Tab -->
            <div class="tab-pane active" id="tab-api">
              <div style="padding: 15px 0;">
          <div class="form-group required">
            <label class="col-sm-2 control-label" for="input-api-key">
              <?php echo $entry_api_key; ?>
              <span data-toggle="tooltip" title="<?php echo $help_api_key; ?>" data-placement="right">
                <i class="fa fa-question-circle"></i>
              </span>
            </label>
            <div class="col-sm-10">
              <div class="input-group">
                <input type="text" name="paypercut_api_key" value="<?php echo $paypercut_api_key; ?>" placeholder="sk_test_... or sk_live_..." id="input-api-key" class="form-control" required />
                <span class="input-group-btn">
                  <button type="button" class="btn btn-info" onclick="testApiConnection()" data-toggle="tooltip" title="Verify your API key is valid" data-placement="top">
                    <i class="fa fa-plug"></i> <?php echo $button_test_connection; ?>
                  </button>
                </span>
              </div>
              <?php if ($paypercut_api_key) { ?>
                <?php if ($paypercut_mode == 'test') { ?>
                <span class="help-block" style="color: #ff9800; font-weight: bold;"><i class="fa fa-flask"></i> <?php echo $text_mode_test; ?></span>
                <?php } elseif ($paypercut_mode == 'live') { ?>
                <span class="help-block" style="color: #4caf50; font-weight: bold;"><i class="fa fa-check-circle"></i> <?php echo $text_mode_live; ?></span>
                <?php } elseif ($paypercut_mode == 'unknown') { ?>
                <span class="help-block" style="color: #f44336; font-weight: bold;"><i class="fa fa-exclamation-triangle"></i> <?php echo $text_mode_unknown; ?></span>
                <?php } ?>
              <?php } ?>
              <div id="connection-status" style="margin-top: 10px;"></div>
              <?php if ($error_api_key) { ?>
              <div class="text-danger"><?php echo $error_api_key; ?></div>
              <?php } ?>
            </div>
          </div>
          <div class="form-group">
            <label class="col-sm-2 control-label" for="input-environment">
              <?php echo $entry_environment; ?>
              <span data-toggle="tooltip" title="<?php echo $help_environment; ?>" data-placement="right">
                <i class="fa fa-question-circle"></i>
              </span>
            </label>
            <div class="col-sm-10">
              <select name="paypercut_environment" id="input-environment" class="form-control">
                <?php foreach ($paypercut_environments as $environment_option) { ?>
                <?php if ($environment_option == $paypercut_environment) { ?>
                <option value="<?php echo $environment_option; ?>" selected="selected"><?php echo ucfirst($environment_option); ?></option>
                <?php } else { ?>
                <option value="<?php echo $environment_option; ?>"><?php echo ucfirst($environment_option); ?></option>
                <?php } ?>
                <?php } ?>
              </select>
              <span class="help-block"><i class="fa fa-info-circle"></i> <?php echo $help_environment; ?> <code><?php echo $paypercut_api_base; ?></code></span>
            </div>
          </div>
          <?php if ($payment_method_configs) { ?>
          <div class="form-group">
            <label class="col-sm-2 control-label" for="input-payment-method-config">
              <?php echo $entry_payment_method_config; ?>
              <span data-toggle="tooltip" title="<?php echo $help_payment_method_config; ?>" data-placement="right">
                <i class="fa fa-question-circle"></i>
              </span>
            </label>
            <div class="col-sm-10">
              <select name="paypercut_payment_method_config" id="input-payment-method-config" class="form-control">
                <option value="">Default (All Payment Methods)</option>
                <?php foreach ($payment_method_configs as $config) { ?>
                <?php if ($config['id'] == $paypercut_payment_method_config) { ?>
                <option value="<?php echo $config['id']; ?>" selected="selected"><?php echo $config['name']; ?><?php if ($config['description']) { ?> - <?php echo $config['description']; ?><?php } ?></option>
                <?php } else { ?>
                <option value="<?php echo $config['id']; ?>"><?php echo $config['name']; ?><?php if ($config['description']) { ?> - <?php echo $config['description']; ?><?php } ?></option>
                <?php } ?>
                <?php } ?>
              </select>
              <span class="help-block"><i class="fa fa-info-circle"></i> <?php echo $help_payment_method_config; ?></span>
            </div>
          </div>
          <?php } ?>
              </div>
            </div>
            
            <!-- Payment Settings Tab -->
            <div class="tab-pane" id="tab-payment">
              <div style="padding: 15px 0;">
          <div class="form-group" id="statement-descriptor-group">
            <label class="col-sm-2 control-label" for="input-statement-descriptor">
              <?php echo $entry_statement_descriptor; ?>
              <span data-toggle="tooltip" title="<?php echo $help_statement_descriptor; ?>" data-placement="right">
                <i class="fa fa-question-circle"></i>
              </span>
            </label>
            <div class="col-sm-10">
              <input type="text" name="paypercut_statement_descriptor" value="<?php echo $paypercut_statement_descriptor; ?>" placeholder="YOUR STORE NAME" id="input-statement-descriptor" class="form-control" maxlength="22" style="text-transform: uppercase;" />
              <span class="help-block"><i class="fa fa-info-circle"></i> <?php echo $help_statement_descriptor; ?></span>
              <span class="help-block" style="color: #999;"><i class="fa fa-text-width"></i> <span id="char-count"><?php echo strlen($paypercut_statement_descriptor); ?></span>/22 characters</span>
              <div id="statement-preview" style="margin-top: 10px; padding: 15px; background: linear-gradient(135deg, rgb(27, 38, 63) 0%, rgb(27, 38, 63) 50%, rgb(230, 253, 83) 50%, rgb(230, 253, 83) 100%); border-radius: 8px; color: white; font-family: monospace; box-shadow: 0 4px 12px rgba(27, 38, 63, 0.3);">
                <div style="font-size: 11px; opacity: 0.9; margin-bottom: 5px; text-shadow: 0 1px 2px rgba(0,0,0,0.3);">BANK STATEMENT PREVIEW</div>
                <strong style="font-size: 14px; text-shadow: 0 1px 2px rgba(0,0,0,0.3);"><?php echo $text_statement_preview; ?>:</strong> <span id="preview-text" style="font-weight: bold; font-size: 14px; text-shadow: 0 1px 2px rgba(0,0,0,0.3);"><?php echo $paypercut_statement_descriptor ? $paypercut_statement_descriptor : 'PAYPERCUT'; ?></span>
              </div>
            </div>
          </div>
          <div class="panel panel-info" style="margin-top: 20px;">
            <div class="panel-heading">
              <h3 class="panel-title"><i class="fa fa-mobile"></i> <?php echo $text_wallet_settings; ?></h3>
            </div>
            <div class="panel-body">
              <div class="form-group">
                <label class="col-sm-2 control-label" for="input-google-pay">
                  <i class="fa fa-google"></i> <?php echo $entry_google_pay; ?>
                  <span data-toggle="tooltip" title="<?php echo $help_google_pay; ?>" data-placement="right">
                    <i class="fa fa-question-circle"></i>
                  </span>
                </label>
                <div class="col-sm-10">
                  <select name="paypercut_google_pay" id="input-google-pay" class="form-control">
                    <?php if ($paypercut_google_pay) { ?>
                    <option value="1" selected="selected"><?php echo $text_enabled; ?></option>
                    <option value="0"><?php echo $text_disabled; ?></option>
                    <?php } else { ?>
                    <option value="1"><?php echo $text_enabled; ?></option>
                    <option value="0" selected="selected"><?php echo $text_disabled; ?></option>
                    <?php } ?>
                  </select>
                  <span class="help-block"><i class="fa fa-info-circle"></i> <?php echo $help_google_pay; ?></span>
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-2 control-label" for="input-apple-pay">
                  <i class="fa fa-apple"></i> <?php echo $entry_apple_pay; ?>
                  <span data-toggle="tooltip" title="<?php echo $help_apple_pay; ?>" data-placement="right">
                    <i class="fa fa-question-circle"></i>
                  </span>
                </label>
                <div class="col-sm-10">
                  <select name="paypercut_apple_pay" id="input-apple-pay" class="form-control">
                    <?php if ($paypercut_apple_pay) { ?>
                    <option value="1" selected="selected"><?php echo $text_enabled; ?></option>
                    <option value="0"><?php echo $text_disabled; ?></option>
                    <?php } else { ?>
                    <option value="1"><?php echo $text_enabled; ?></option>
                    <option value="0" selected="selected"><?php echo $text_disabled; ?></option>
                    <?php } ?>
                  </select>
                  <span class="help-block"><i class="fa fa-info-circle"></i> <?php echo $help_apple_pay; ?></span>
                  <div id="apple-domain-file-status" style="margin-top: 10px;">
                    <?php if ($apple_domain_status['present'] && $apple_domain_status['reachable'] !== '0') { ?>
                      <div class="alert alert-success" style="margin-bottom: 0;">
                        <i class="fa fa-check-circle"></i> <?php echo $text_apple_domain_file_ok; ?>
                        <small class="text-muted" style="display: block; margin-top: 3px;">
                          <?php echo sprintf($text_apple_domain_file_path, '<code>' . $apple_domain_status['path'] . '</code>'); ?>
                        </small>
                      </div>
                    <?php } elseif ($apple_domain_status['present'] && $apple_domain_status['reachable'] === '0') { ?>
                      <div class="alert alert-warning" style="margin-bottom: 0;">
                        <i class="fa fa-exclamation-triangle"></i> <?php echo $text_apple_domain_file_unreachable; ?>
                        <small class="text-muted" style="display: block; margin-top: 3px;">
                          <?php echo sprintf($text_apple_domain_file_path, '<code>' . $apple_domain_status['path'] . '</code>'); ?>
                        </small>
                      </div>
                    <?php } else { ?>
                      <div class="alert alert-danger" style="margin-bottom: 0;">
                        <i class="fa fa-times-circle"></i> <?php echo $text_apple_domain_file_missing; ?>
                        <small class="text-muted" style="display: block; margin-top: 3px;">
                          <?php echo sprintf($text_apple_domain_file_path, '<code>' . $apple_domain_status['path'] . '</code>'); ?>
                        </small>
                      </div>
                    <?php } ?>
                    <button type="button" class="btn btn-default btn-sm" onclick="refreshAppleDomainFile()" style="margin-top: 8px;">
                      <i class="fa fa-refresh"></i> <?php echo $button_apple_domain_refresh; ?>
                    </button>
                  </div>
                </div>
              </div>

              <div class="form-group">
                <label class="col-sm-2 control-label" for="input-checkout-mode">
                  <i class="fa fa-desktop"></i> <?php echo $entry_checkout_mode; ?>
                  <span data-toggle="tooltip" title="<?php echo $help_checkout_mode; ?>" data-placement="right">
                    <i class="fa fa-question-circle"></i>
                  </span>
                </label>
                <div class="col-sm-10">
                  <select name="paypercut_checkout_mode" id="input-checkout-mode" class="form-control">
                    <option value="hosted" <?php if ($paypercut_checkout_mode == 'hosted') { ?>selected="selected"<?php } ?>><?php echo $text_hosted; ?></option>
                    <option value="embedded" <?php if ($paypercut_checkout_mode == 'embedded') { ?>selected="selected"<?php } ?>><?php echo $text_embedded; ?></option>
                  </select>
                  <span class="help-block"><i class="fa fa-info-circle"></i> <?php echo $help_checkout_mode; ?></span>
                </div>
              </div>
            </div>
          </div>
              </div>
            </div>
            
            <!-- Webhooks Tab -->
            <div class="tab-pane" id="tab-webhooks">
              <div style="padding: 15px 0;">
          <div class="alert alert-info">
            <i class="fa fa-info-circle"></i> <strong>What are webhooks?</strong>
            Webhooks allow Paypercut to automatically notify your store when payment events occur (successful payments, failures, refunds). This ensures your order statuses are always up-to-date.
          </div>
          
          <div class="form-group">
            <label class="col-sm-2 control-label">
              <?php echo $entry_webhook_url; ?>
              <span data-toggle="tooltip" title="This URL will receive notifications from Paypercut when payment events occur" data-placement="right">
                <i class="fa fa-question-circle"></i>
              </span>
            </label>
            <div class="col-sm-10">
              <div class="input-group">
                <input type="text" value="<?php echo $paypercut_webhook_url; ?>" id="webhook-url" class="form-control" readonly />
                <span class="input-group-btn">
                  <button type="button" class="btn btn-default" onclick="copyWebhookUrl()" data-toggle="tooltip" title="Copy webhook URL to clipboard">
                    <i class="fa fa-copy"></i> Copy
                  </button>
                </span>
              </div>
              <span class="help-block"><i class="fa fa-info-circle"></i> <?php echo $text_webhook_info; ?></span>
            </div>
          </div>
          
          <div class="form-group">
            <label class="col-sm-2 control-label">Webhook Status</label>
            <div class="col-sm-10">
              <div id="webhook-status">
                <?php if ($webhook_status['configured']) { ?>
                  <div class="well well-sm" style="background: #dff0d8; border-color: #d6e9c6;">
                    <h4><span class="label label-success"><i class="fa fa-check"></i> <?php echo $text_webhook_configured; ?></span></h4>
                    <p style="margin-top: 10px;"><i class="fa fa-check-circle"></i> <?php echo $webhook_status['message']; ?></p>
                    <?php if ($webhook_status['webhook_id']) { ?>
                      <p style="margin-top: 5px;"><small><strong>Webhook ID:</strong> <code><?php echo $webhook_status['webhook_id']; ?></code></small></p>
                    <?php } ?>
                    <button type="button" class="btn btn-danger btn-sm" onclick="deleteWebhook()" style="margin-top: 10px;" data-toggle="tooltip" title="Remove webhook from Paypercut">
                      <i class="fa fa-trash"></i> <?php echo $text_webhook_delete; ?>
                    </button>
                  </div>
                <?php } else { ?>
                  <div class="well well-sm" style="background: #fcf8e3; border-color: #faebcc;">
                    <h4><span class="label label-warning"><i class="fa fa-exclamation-triangle"></i> <?php echo $text_webhook_not_configured; ?></span></h4>
                    <p style="margin-top: 10px;"><i class="fa fa-info-circle"></i> <?php echo $webhook_status['message']; ?></p>
                    <button type="button" class="btn btn-primary btn-sm" onclick="createWebhook()" style="margin-top: 10px;" data-toggle="tooltip" title="Automatically create webhook in Paypercut">
                      <i class="fa fa-plus"></i> <?php echo $text_webhook_create; ?>
                    </button>
                  </div>
                <?php } ?>
              </div>
            </div>
          </div>
              </div>
            </div>
            
            <!-- General Settings Tab -->
            <div class="tab-pane" id="tab-general">
              <div style="padding: 15px 0;">
          <div class="form-group">
            <label class="col-sm-2 control-label" for="input-order-status">
              <?php echo $entry_order_status; ?>
              <span data-toggle="tooltip" title="Order status when payment is successful" data-placement="right">
                <i class="fa fa-question-circle"></i>
              </span>
            </label>
            <div class="col-sm-10">
              <select name="paypercut_order_status_id" id="input-order-status" class="form-control">
                <?php foreach ($order_statuses as $order_status) { ?>
                <?php if ($order_status['order_status_id'] == $paypercut_order_status_id) { ?>
                <option value="<?php echo $order_status['order_status_id']; ?>" selected="selected"><?php echo $order_status['name']; ?></option>
                <?php } else { ?>
                <option value="<?php echo $order_status['order_status_id']; ?>"><?php echo $order_status['name']; ?></option>
                <?php } ?>
                <?php } ?>
              </select>
              <span class="help-block"><i class="fa fa-info-circle"></i> The order status that will be set when a payment is completed successfully</span>
            </div>
          </div>
          
          <div class="form-group required">
            <label class="col-sm-2 control-label" for="input-status">
              <?php echo $entry_status; ?>
              <span data-toggle="tooltip" title="Enable or disable Paypercut payment method" data-placement="right">
                <i class="fa fa-question-circle"></i>
              </span>
            </label>
            <div class="col-sm-10">
              <select name="paypercut_status" id="input-status" class="form-control">
                <?php if ($paypercut_status) { ?>
                <option value="1" selected="selected"><?php echo $text_enabled; ?></option>
                <option value="0"><?php echo $text_disabled; ?></option>
                <?php } else { ?>
                <option value="1"><?php echo $text_enabled; ?></option>
                <option value="0" selected="selected"><?php echo $text_disabled; ?></option>
                <?php } ?>
              </select>
              <span class="help-block"><i class="fa fa-info-circle"></i> Enable or disable this payment method on checkout</span>
            </div>
          </div>
          
          <div class="form-group">
            <label class="col-sm-2 control-label" for="input-sort-order">
              <?php echo $entry_sort_order; ?>
              <span data-toggle="tooltip" title="Display order on checkout page (lower numbers appear first)" data-placement="right">
                <i class="fa fa-question-circle"></i>
              </span>
            </label>
            <div class="col-sm-10">
              <input type="text" name="paypercut_sort_order" value="<?php echo $paypercut_sort_order; ?>" placeholder="0" id="input-sort-order" class="form-control" />
              <span class="help-block"><i class="fa fa-info-circle"></i> Payment methods are sorted by this value on the checkout page</span>
            </div>
          </div>
          
          <div class="form-group">
            <label class="col-sm-2 control-label" for="input-logging">
              <?php echo $entry_logging; ?>
              <span data-toggle="tooltip" title="Enable logging of API requests, webhooks, and errors" data-placement="right">
                <i class="fa fa-question-circle"></i>
              </span>
            </label>
            <div class="col-sm-10">
              <select name="paypercut_logging" id="input-logging" class="form-control">
                <?php if ($paypercut_logging) { ?>
                <option value="1" selected="selected"><?php echo $text_enabled; ?></option>
                <option value="0"><?php echo $text_disabled; ?></option>
                <?php } else { ?>
                <option value="1"><?php echo $text_enabled; ?></option>
                <option value="0" selected="selected"><?php echo $text_disabled; ?></option>
                <?php } ?>
              </select>
              <span class="help-block">
                <i class="fa fa-info-circle"></i> When enabled, logs API requests, webhook events, and errors. 
                <span class="text-warning"><strong>Warning:</strong> Logs may contain sensitive data. Disable in production unless debugging.</span>
              </span>
            </div>
          </div>
          
          <div class="form-group">
            <label class="col-sm-2 control-label">
              Logs & Debugging
            </label>
            <div class="col-sm-10">
              <a href="index.php?route=sale/paypercut_logs&token=<?php echo $token; ?>" class="btn btn-info" target="_blank">
                <i class="fa fa-list-alt"></i> View Logs (Webhooks & Errors)
              </a>
              <span class="help-block"><i class="fa fa-info-circle"></i> View webhook events, API errors, and debug information</span>
            </div>
          </div>
              </div>
            </div>
            

            <!-- Diagnostics Tab -->
            <div class="tab-pane" id="tab-diagnostics">
              <div style="padding: 15px 0;" id="paypercut-debug-session"
                   data-token="<?php echo $token; ?>"
                   data-poll-seconds="<?php echo (int)$telemetry_poll_seconds; ?>"
                   data-now="<?php echo (int)$telemetry_now; ?>"
                   data-state="<?php echo htmlspecialchars($telemetry_state['state'], ENT_QUOTES, 'UTF-8'); ?>"
                   data-expires-at="<?php echo (int)$telemetry_state['expires_at']; ?>">

                <h4><?php echo $heading_telemetry; ?></h4>

                <div id="paypercut-debug-session-status" class="alert" style="display:none;"></div>

                <div data-paypercut-state="idle" <?php echo $telemetry_state['state'] == 'idle' ? '' : 'style="display:none;"'; ?>>
                  <p><?php echo $text_telemetry_idle_lead; ?></p>
                  <p class="help-block"><?php echo $text_telemetry_idle_help; ?></p>
                  <?php echo $telemetry_disclosure; ?>
                  <?php if ($telemetry_start_enabled) { ?>
                  <button type="button" class="btn btn-primary paypercut-debug-start"><i class="fa fa-play"></i> <?php echo $button_telemetry_start; ?></button>
                  <?php } ?>
                </div>

                <div data-paypercut-state="running" <?php echo $telemetry_state['state'] == 'running' ? '' : 'style="display:none;"'; ?>>
                  <p><strong><i class="fa fa-circle text-danger"></i>
                    <?php echo sprintf($text_telemetry_running, '<span data-paypercut-countdown>-</span>'); ?>
                  </strong></p>
                  <p class="help-block">
                    <?php echo sprintf($text_telemetry_started_by, '<span data-paypercut-started-by>' . htmlspecialchars($telemetry_state['started_by_name'], ENT_QUOTES, 'UTF-8') . '</span>', '<span data-paypercut-ends-at>' . $telemetry_ends_at . '</span>'); ?>
                  </p>
                  <p>
                    <?php echo $text_telemetry_session_id; ?>
                    <code data-paypercut-session-id><?php echo htmlspecialchars($telemetry_state['session_id'], ENT_QUOTES, 'UTF-8'); ?></code>
                    <button type="button" class="btn btn-link btn-xs" data-paypercut-copy><?php echo $button_telemetry_copy; ?></button>
                  </p>
                  <p class="help-block">
                    <?php echo sprintf($text_telemetry_counters, '<span data-paypercut-sent>' . (int)$telemetry_state['events_sent'] . '</span>', '<span data-paypercut-dropped>' . (int)$telemetry_state['events_dropped'] . '</span>'); ?>
                  </p>
                  <button type="button" class="btn btn-danger paypercut-debug-stop"><i class="fa fa-stop"></i> <?php echo $button_telemetry_stop; ?></button>
                </div>

                <div data-paypercut-state="ended" <?php echo $telemetry_state['state'] == 'ended' ? '' : 'style="display:none;"'; ?>>
                  <p><strong><?php echo $text_telemetry_ended; ?></strong> <?php echo $text_telemetry_ended_help; ?></p>
                  <p>
                    <?php echo sprintf($text_telemetry_last_session_id, '<code data-paypercut-session-id>' . htmlspecialchars($telemetry_state['session_id'], ENT_QUOTES, 'UTF-8') . '</code>'); ?>
                    <button type="button" class="btn btn-link btn-xs" data-paypercut-copy><?php echo $button_telemetry_copy; ?></button>
                  </p>
                  <?php if ($telemetry_start_enabled) { ?>
                  <button type="button" class="btn btn-primary paypercut-debug-start"><i class="fa fa-play"></i> <?php echo $button_telemetry_start; ?></button>
                  <?php } ?>
                </div>

                <div data-paypercut-state="failed" <?php echo $telemetry_state['state'] == 'failed' ? '' : 'style="display:none;"'; ?>>
                  <div class="alert alert-danger" data-paypercut-failed-message><?php echo htmlspecialchars($telemetry_state['message'], ENT_QUOTES, 'UTF-8'); ?></div>
                  <p data-paypercut-reference <?php echo $telemetry_state['trace_id'] == '' ? 'style="display:none;"' : ''; ?>>
                    <?php echo $text_telemetry_reference; ?>
                    <code data-paypercut-trace-id><?php echo htmlspecialchars($telemetry_state['trace_id'], ENT_QUOTES, 'UTF-8'); ?></code>
                    <button type="button" class="btn btn-link btn-xs" data-paypercut-copy><?php echo $button_telemetry_copy; ?></button>
                  </p>
                  <?php if ($telemetry_start_enabled) { ?>
                  <button type="button" class="btn btn-primary paypercut-debug-start"><i class="fa fa-refresh"></i> <?php echo $button_telemetry_retry; ?></button>
                  <?php } ?>
                </div>

                <?php if ($telemetry_log) { ?>
                <details data-paypercut-log style="margin-top: 20px;">
                  <summary><?php echo sprintf($text_telemetry_log_summary, count($telemetry_log)); ?></summary>
                  <p class="help-block"><?php echo sprintf($text_telemetry_log_help, (int)$telemetry_log_max); ?></p>
                  <div style="max-height: 320px; overflow: auto;">
                    <table class="table table-condensed table-striped">
                      <thead>
                        <tr>
                          <td><?php echo $text_telemetry_log_time; ?></td>
                          <td><?php echo $text_telemetry_log_event; ?></td>
                          <td><?php echo $text_telemetry_log_detail; ?></td>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($telemetry_log as $telemetry_entry) { ?>
                        <tr>
                          <td><code><?php echo htmlspecialchars($telemetry_entry['occurred_at'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                          <td><code><?php echo htmlspecialchars($telemetry_entry['event'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                          <td><?php echo htmlspecialchars($telemetry_entry['detail'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                        <?php } ?>
                      </tbody>
                    </table>
                  </div>
                  <p><a href="#" onclick="$('#paypercut-debug-raw').toggle(); return false;"><?php echo $text_telemetry_log_raw; ?></a></p>
                  <pre id="paypercut-debug-raw" style="display:none; max-height: 320px; overflow: auto;"><?php echo htmlspecialchars($telemetry_log_raw, ENT_QUOTES, 'UTF-8'); ?></pre>
                </details>
                <?php } ?>
              </div>
            </div>

          </div><!-- /.tab-content -->
        </form>
      </div>
    </div>
  </div>
</div>
<div class="modal fade" id="paypercut-debug-modal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title"><?php echo $text_telemetry_modal_title; ?></h4>
      </div>
      <div class="modal-body">
        <p><?php echo $text_telemetry_modal_lead; ?></p>
        <?php echo $telemetry_disclosure; ?>
        <p class="help-block"><?php echo $text_telemetry_modal_duration; ?></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal"><?php echo $button_cancel; ?></button>
        <button type="button" class="btn btn-primary" id="paypercut-debug-confirm"><?php echo $button_telemetry_start_confirm; ?></button>
      </div>
    </div>
  </div>
</div>

<script type="text/javascript">
// Initialize tooltips
$('[data-toggle="tooltip"]').tooltip();

// Statement descriptor preview and character count
function updateStatementPreview() {
    var descriptor = $('#input-statement-descriptor').val().toUpperCase() || 'PAYPERCUT';
    var length = descriptor.length;
    
    $('#input-statement-descriptor').val(descriptor);
    $('#preview-text').text(descriptor);
    $('#char-count').text(length);
}

$('#input-statement-descriptor').on('keyup', updateStatementPreview);

// Trigger initial character count
updateStatementPreview();

// Test API connection
function testApiConnection() {
    var apiKey = $('#input-api-key').val();
    
    if (!apiKey) {
        alert('Please enter an API key first');
        return;
    }
    
    $('#connection-status').html('<div class="alert alert-info"><i class="fa fa-spinner fa-spin"></i> <?php echo $text_testing_connection; ?></div>');
    
    $.ajax({
        url: 'index.php?route=extension/payment/paypercut/testConnection&token=<?php echo $token; ?>',
        type: 'post',
        data: { api_key: apiKey },
        dataType: 'json',
        success: function(json) {
            if (json.error) {
                $('#connection-status').html(
                    '<div class="alert alert-danger alert-dismissible">' +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '<i class="fa fa-times-circle"></i> <strong>Connection Failed:</strong> ' + json.error +
                    '</div>'
                );
            } else if (json.success) {
                var html = '<div class="alert alert-success alert-dismissible">' +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '<i class="fa fa-check-circle"></i> <strong>' + json.message + '</strong><br>' +
                    '<strong>Mode:</strong> ' + json.mode.toUpperCase();
                
                if (json.account_name) {
                    html += '<br><strong>Account:</strong> ' + json.account_name;
                }
                
                html += '</div>';
                $('#connection-status').html(html);
            }
        },
        error: function(xhr, status, error) {
            $('#connection-status').html(
                '<div class="alert alert-danger alert-dismissible">' +
                '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                '<i class="fa fa-exclamation-triangle"></i> <strong>Error:</strong> ' + error +
                '</div>'
            );
        }
    });
}

// Copy webhook URL to clipboard
function copyWebhookUrl() {
    var webhookUrl = document.getElementById('webhook-url');
    webhookUrl.select();
    webhookUrl.setSelectionRange(0, 99999);
    document.execCommand('copy');
    
    alert('Webhook URL copied to clipboard!');
}

// Create webhook via API
function createWebhook() {
    if (!confirm('This will create a webhook in your Paypercut account with all events enabled. Continue?')) {
        return;
    }
    
    $('#webhook-status').html('<i class="fa fa-spinner fa-spin"></i> <?php echo $text_webhook_creating; ?>');
    
    $.ajax({
        url: 'index.php?route=extension/payment/paypercut/createWebhook&token=<?php echo $token; ?>',
        type: 'post',
        dataType: 'json',
        success: function(json) {
            if (json.error) {
                alert('Error: ' + json.error);
            } else if (json.success) {
                alert(json.success);
            }
            location.reload();
        },
        error: function(xhr, status, error) {
            alert('Error creating webhook: ' + error);
            location.reload();
        }
    });
}

// Refresh Apple Pay domain-association file (CDN with bundled fallback)
function refreshAppleDomainFile() {
    var $status = $('#apple-domain-file-status');
    $status.find('.alert').replaceWith(
        '<div class="alert alert-info" style="margin-bottom: 0;">' +
        '<i class="fa fa-spinner fa-spin"></i> <?php echo $text_apple_domain_file_refreshing; ?>' +
        '</div>'
    );

    $.ajax({
        url: 'index.php?route=extension/payment/paypercut/refreshAppleDomainFile&token=<?php echo $token; ?>',
        type: 'post',
        dataType: 'json',
        success: function(json) {
            if (json.success) {
                location.reload();
            } else {
                var msg = json.error || 'Failed to refresh Apple Pay verification file';
                if (json.reason) { msg += ' (reason: ' + json.reason + ')'; }
                $status.find('.alert').replaceWith(
                    '<div class="alert alert-danger" style="margin-bottom: 0;">' +
                    '<i class="fa fa-times-circle"></i> ' + msg +
                    '</div>'
                );
            }
        },
        error: function(xhr, status, error) {
            $status.find('.alert').replaceWith(
                '<div class="alert alert-danger" style="margin-bottom: 0;">' +
                '<i class="fa fa-times-circle"></i> Network error: ' + error +
                '</div>'
            );
        }
    });
}

// Delete webhook via API
function deleteWebhook() {
    if (!confirm('This will delete the webhook from your Paypercut account. You can recreate it at any time. Continue?')) {
        return;
    }
    
    $('#webhook-status').html('<i class="fa fa-spinner fa-spin"></i> <?php echo $text_webhook_deleting; ?>');
    
    $.ajax({
        url: 'index.php?route=extension/payment/paypercut/deleteWebhook&token=<?php echo $token; ?>',
        type: 'post',
        dataType: 'json',
        success: function(json) {
            if (json.error) {
                alert('Error: ' + json.error);
            } else if (json.success) {
                alert(json.success);
            }
            location.reload();
        },
        error: function(xhr, status, error) {
            alert('Error deleting webhook: ' + error);
            location.reload();
        }
    });
}

// ---------------------------------------------------------------------------
// Debug (telemetry) session panel: the consent modal, the live countdown, and
// a poll that doubles as the delivery trigger while this screen is open.
// ---------------------------------------------------------------------------
(function () {
    var panel = document.getElementById('paypercut-debug-session');

    if (!panel) {
        return;
    }

    var i18n = {
        copied: <?php echo json_encode($text_telemetry_copied); ?>,
        copy: <?php echo json_encode($button_telemetry_copy); ?>,
        starting: <?php echo json_encode($text_telemetry_starting); ?>,
        startSession: <?php echo json_encode($button_telemetry_start_confirm); ?>,
        stopping: <?php echo json_encode($text_telemetry_stopping); ?>,
        stopNow: <?php echo json_encode($button_telemetry_stop); ?>,
        sessionEnded: <?php echo json_encode($text_telemetry_session_ended); ?>,
        networkError: <?php echo json_encode($text_telemetry_network_error); ?>,
        adminUnreachable: <?php echo json_encode($text_telemetry_admin_unreachable); ?>
    };

    var token = panel.getAttribute('data-token');
    var pollSeconds = parseInt(panel.getAttribute('data-poll-seconds'), 10) || 60;
    var state = {
        state: panel.getAttribute('data-state'),
        expires_at: parseInt(panel.getAttribute('data-expires-at'), 10) || 0
    };

    var pollTimer = null;
    var tickTimer = null;
    // Guards against the deadline tick firing a request every second.
    var polling = false;
    var deadlineConfirmed = false;
    var pollFailures = 0;
    // Offset between this browser's clock and the server's, so a wrong local
    // clock cannot make the countdown disagree with when the session ends.
    var clockOffset = (parseInt(panel.getAttribute('data-now'), 10) || 0) - Math.floor(Date.now() / 1000);

    function serverNow() {
        return Math.floor(Date.now() / 1000) + clockOffset;
    }

    function show(message, kind) {
        var box = document.getElementById('paypercut-debug-session-status');
        if (!box) { return; }
        box.textContent = message;
        box.className = 'alert alert-' + (kind === 'error' ? 'danger' : 'success');
        box.style.display = '';
    }

    function hideStatus() {
        var box = document.getElementById('paypercut-debug-session-status');
        if (box) { box.style.display = 'none'; }
    }

    function fill(selector, value) {
        var nodes = panel.querySelectorAll(selector);
        for (var i = 0; i < nodes.length; i++) {
            nodes[i].textContent = value;
        }
    }

    function pad(value) {
        return value < 10 ? '0' + value : String(value);
    }

    function formatClock(unixSeconds) {
        var date = new Date(unixSeconds * 1000);
        return pad(date.getHours()) + ':' + pad(date.getMinutes());
    }

    function formatRemaining(seconds) {
        if (seconds < 0) { seconds = 0; }
        return Math.floor(seconds / 60) + ':' + pad(seconds % 60);
    }

    function render() {
        var blocks = panel.querySelectorAll('[data-paypercut-state]');
        for (var i = 0; i < blocks.length; i++) {
            blocks[i].style.display = blocks[i].getAttribute('data-paypercut-state') === state.state ? '' : 'none';
        }

        fill('[data-paypercut-session-id]', state.session_id || '');
        fill('[data-paypercut-started-by]', state.started_by_name || '');
        fill('[data-paypercut-sent]', String(state.events_sent || 0));
        fill('[data-paypercut-dropped]', String(state.events_dropped || 0));
        fill('[data-paypercut-ends-at]', state.expires_at ? formatClock(state.expires_at) : '');

        if (state.state === 'failed') {
            // The failed block carries this message itself. Leaving the transient
            // status box up as well prints the same red notice twice.
            hideStatus();
            fill('[data-paypercut-failed-message]', state.message || '');
            fill('[data-paypercut-trace-id]', state.trace_id || '');

            var reference = panel.querySelector('[data-paypercut-reference]');
            if (reference) {
                reference.style.display = state.trace_id ? '' : 'none';
            }
        }

        tick();
    }

    function tick() {
        if (state.state !== 'running') { return; }

        var remaining = (state.expires_at || 0) - serverNow();
        fill('[data-paypercut-countdown]', formatRemaining(remaining));

        // Ask the server to confirm and tear down - once, not once per second.
        if (remaining <= 0 && !deadlineConfirmed) {
            deadlineConfirmed = true;
            poll();
        }
    }

    function apply(next) {
        var ended = state.state === 'running' && next.state !== 'running';
        var started = state.state !== 'running' && next.state === 'running';

        state = next;
        pollFailures = 0;

        if (started) {
            deadlineConfirmed = false;
            // Starting clears the log server-side. This block is rendered once
            // per page load, so without this the merchant expands it and reads
            // the previous session's events under the new session's heading.
            dropSentLog();
        }

        if (typeof next.now === 'number') {
            clockOffset = next.now - Math.floor(Date.now() / 1000);
        }

        render();

        if (next.state !== 'running') {
            stopTicking();
        } else {
            startTicking();
        }

        if (ended) {
            show(i18n.sessionEnded, 'success');
        }
    }

    function dropSentLog() {
        var log = panel.querySelector('[data-paypercut-log]');
        if (log && log.parentNode) {
            log.parentNode.removeChild(log);
        }
    }

    function request(action, onDone) {
        $.ajax({
            url: 'index.php?route=extension/payment/paypercut/' + action + '&token=' + encodeURIComponent(token),
            type: 'post',
            dataType: 'json',
            success: function (data) { onDone(data, null); },
            // A host WAF answering the admin route with HTML lands here.
            error: function (xhr) { onDone(xhr.responseJSON || null, true); }
        });
    }

    function poll() {
        if (polling) { return; }
        polling = true;

        request('debugSessionStatus', function (data, failed) {
            polling = false;

            if (failed && !data) {
                pollFailures++;

                // A blip should not freeze the panel for good, but a host that
                // keeps answering with HTML should not be polled forever either.
                if (pollFailures >= 3) {
                    stopPolling();
                    stopTicking();
                    show(i18n.adminUnreachable, 'error');
                    return;
                }

                schedulePoll(pollFailures * 30000);
                return;
            }

            if (data && data.success && data.data) {
                apply(data.data);
            }

            schedulePoll();
        });
    }

    function schedulePoll(overrideMs) {
        stopPolling();

        if (state.state !== 'running' || document.hidden) { return; }

        // +/-20% jitter so many open dashboards do not land together.
        var delay = overrideMs || (pollSeconds * 1000) * (0.8 + Math.random() * 0.4);
        pollTimer = window.setTimeout(poll, delay);
    }

    function stopPolling() {
        if (pollTimer) { window.clearTimeout(pollTimer); pollTimer = null; }
    }

    function startTicking() {
        if (!tickTimer) { tickTimer = window.setInterval(tick, 1000); }
    }

    function stopTicking() {
        if (tickTimer) { window.clearInterval(tickTimer); tickTimer = null; }
    }

    $(panel).on('click', '.paypercut-debug-start', function (event) {
        event.preventDefault();
        hideStatus();
        $('#paypercut-debug-modal').modal('show');
    });

    $(panel).on('click', '.paypercut-debug-stop', function (event) {
        event.preventDefault();

        var button = this;
        button.disabled = true;
        button.textContent = i18n.stopping;

        request('stopDebugSession', function (data, failed) {
            button.disabled = false;
            button.textContent = i18n.stopNow;

            if (failed && !data) {
                show(i18n.networkError, 'error');
                return;
            }

            if (data && data.success && data.data) {
                apply(data.data);
            } else if (data && data.message) {
                show(data.message, 'error');
            }
        });
    });

    $(panel).on('click', '[data-paypercut-copy]', function (event) {
        event.preventDefault();

        // Two rows offer a Copy button - the session id and the support
        // reference - so copy the code beside this one, not the first on screen.
        var row = $(this).closest('p');
        var node = row.find('code')[0] || panel.querySelector('[data-paypercut-session-id]');
        var value = node ? node.textContent : '';

        if (!value) { return; }

        var button = this;
        var confirmCopy = function () {
            button.textContent = i18n.copied;
            window.setTimeout(function () { button.textContent = i18n.copy; }, 2000);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(confirmCopy, function () {});
            return;
        }

        var field = document.createElement('textarea');
        field.value = value;
        document.body.appendChild(field);
        field.select();

        try {
            document.execCommand('copy');
            confirmCopy();
        } catch (error) {
            // Copying is a convenience; the id is on screen either way.
        }

        document.body.removeChild(field);
    });

    $('#paypercut-debug-confirm').on('click', function (event) {
        event.preventDefault();

        var button = this;
        button.disabled = true;
        button.textContent = i18n.starting;

        request('startDebugSession', function (data, failed) {
            button.disabled = false;
            button.textContent = i18n.startSession;
            $('#paypercut-debug-modal').modal('hide');

            if (failed && !data) {
                show(i18n.networkError, 'error');
                return;
            }

            if (data && data.success && data.data) {
                apply(data.data);
                schedulePoll();
                return;
            }

            if (data && data.message) {
                show(data.message, 'error');
            }

            // A rejected start writes a `failed` record, so re-read it.
            poll();
        });
    });

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stopPolling();
            return;
        }

        if (state.state === 'running') {
            poll();
        }
    });

    // The server painted the current state, so only the live parts need a poll.
    if (state.state === 'running') {
        startTicking();
        tick();
        schedulePoll();
    }
})();

</script>
<?php echo $footer; ?>
