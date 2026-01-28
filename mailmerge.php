<?php
// Copyright (c) 2025 Bennet Becker <dev@bennet.cc>
//
// Permission is hereby granted, free of charge, to any person obtaining a copy
// of this software and associated documentation files (the "Software"), to deal
// in the Software without restriction, including without limitation the rights
// to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
// copies of the Software, and to permit persons to whom the Software is
// furnished to do so, subject to the following conditions:
//
// The above copyright notice and this permission notice shall be included in all
// copies or substantial portions of the Software.
//
// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
// IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
// FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
// AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
// LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
// OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
// SOFTWARE.

const MAILMERGE_PREFIX = "mailmerge";
const MAILMERGE_LOG_FILE = "mailmerge";
const MAILMERGE_VERSION = "0.1";

use bennetcc\mailmerge\traits\DisableUser;
use bennetcc\mailmerge\Log;
use bennetcc\mailmerge\traits\ResolveUsername;
use bennetcc\mailmerge\LogLevel;
use function bennetcc\mailmerge\__;

require_once "util.php";
require_once "log.php";
require_once "traits/DisableUser.php";
require_once "traits/ResolveUsername.php";

/** @noinspection PhpUnused */

class mailmerge extends \rcube_plugin
{
    private rcmail $rc;
    private Log $log;

    private const MODE_HTML = 'html';
    private const MODE_PLAIN = 'plain';
    
    use DisableUser, ResolveUsername;


    public function init(): void
    {
        $this->rc = rcmail::get_instance();
        $this->load_config("config.inc.php.dist");
        $this->load_config();
        $this->log = new Log(MAILMERGE_LOG_FILE, MAILMERGE_PREFIX, $this->rc->config->get(__('log_level'), LogLevel::INFO->value));

        if ($this->is_disabled()) {
            return;
        }

        $this->register_action('plugin.mailmerge', [$this, 'mailmerge_action']);
        $this->register_action('plugin.mailmerge.get-folders', [$this, 'get_folders']);
        $this->register_action('plugin.mailmerge.send-unsent', [$this, 'send_unsent']);

        $this->add_hook("ready", function ($param) {
//            $this->log->debug('ready', $param);
            $prefs = $this->rc->user->get_prefs();
            if ($param['task'] == 'mail' && $param['action'] === 'compose' && $prefs[__("enabled")]) {

                $header = \html::div('row', \html::div('col-12', \html::span("font-weight-bold", "Mail merge options")));

                $sselect = new html_select(["id" => "mailmergesep"]);
                $sselect->add([", (Comma)", "; (Semicolon)", "| (Pipe)", "Tab"], [",", ";", "|", "tab"]);
                $separator = \html::div("form-group row",
                    \html::label(['for' => 'mailmergesep', 'class' => 'col-form-label col-6'], rcube::Q("Separator")) .
                    \html::div('col-6', $sselect->show(["id" => "mailmergesep", "class" => "custom-select form-control pretty-select"])));

                $eselect = new html_select(["id" => "mailmergeencl"]);
                $eselect->add(["\" (Double Quotes)", "' (Single Quote)"], ["\"", "'"]);

                $enclosed = html::div('form-group row',
                    html::label(['for' => 'mailmergeencl', 'class' => 'col-form-label col-6'], rcube::Q("Field Enclosure"))
                    . html::div('form-check col-6',
                        $eselect->show(["id" => "mailmergeencl", "class" => "custom-select form-control pretty-select"])
                    )
                );

                $file = html::div('form-group form-check row',
                    \html::div("col-6", (new html_inputfield(["type" => "file"]))->show(null, ["id" => "mailmergefile"])) .
                    \html::div("col-6", (new html_button([
                        'type' => 'button',
                        'command' => 'plugin.mailmerge',
                        'onclick' => "rcmail.command('plugin.mailmerge', '', event.target, event)",
                        'class' => 'button mailmerge mx-4',
                        'title' => 'Mailmerge',
                        'label' => 'Mailmerge',
                        'domain' => $this->ID,
                        'width' => 32,
                        'height' => 32,
                        'aria-owns' => 'mailmerge',
                        'aria-haspopup' => 'false',
                        'aria-expanded' => 'false',
                    ]))->show(rcube::Q('Mailmerge')))
                );

                $fselect = new html_select(["id" => "mailmergefolder"]);

                $folders = html::div('form-group row',
                    html::label(['for' => 'mailmergefolder', 'class' => 'col-form-label col-6'], rcube::Q("Save to Folder"))
                    . html::div('form-check col-6',
                        $fselect->show(["id" => "mailmergefolder", "class" => "custom-select form-control pretty-select"])
                    )
                );

                $this->api->add_content(\html::div(["style" => "padding-bottom: 1rem; margin: 0", "class" => "file-upload"],
                    $header . $separator . $enclosed . $folders . $file), 'composeoptions');
            }
            if ($param['task'] == 'mail' && $prefs[__("enabled")]) {
                $this->include_script('mailmerge.js');
            }
        });

        $this->add_hook("preferences_list", function ($param) {
            $this->log->trace('preferences_list', $param);
            if ($param['section'] == 'compose' && $param['current'] == 'compose') {
                $prefs = $this->rc->user->get_prefs();
                $blocks = $param["blocks"];
                $blocks["advanced"]["options"]["plugin.mailmerge"] = [
                    'title' => "Enable Mailmerge in Compose view",
                    'content' => (new \html_checkbox(["id" => __("enabled"), "value" => "1", "name" => "_".__("enabled")]))
                        ->show($prefs[__("enabled")] ?? "0"),
                ];
                return ["blocks" => $blocks];
            }
            return $param;
        });

        $this->add_hook("preferences_save", function ($param) {
            if ($param["section"] != "compose") {
                return $param;
            }
            $this->log->debug('preferences_save', $param);
            $param['prefs'][__("enabled")] = filter_input(INPUT_POST, "_".__("enabled"), FILTER_VALIDATE_BOOLEAN);
            $this->log->debug('preferences_save', $param);

            return $param;
        });

        $this->add_hook("message_saved", function($param) {
//            $this->log->debug($param);
        });
        $this->add_hook("message_ready", function($param) {
            $this->log->debug($param);
        });

        $this->add_hook("template_container", function ($param) {
            $folder = $this->rc->storage?->get_folder();
            $delimiter = $this->rc->storage?->get_hierarchy_delimiter();
            if ($folder && $delimiter) {
                $path = explode($delimiter, $folder);
                $under_drafts = false;
                foreach ($path as $i => $subpath) {
                    $slice = array_slice($path, 0, $i + 1);
                    $f = implode($delimiter, $slice);
                    $folder_info = $this->rc->storage?->folder_info($f);
                    if (in_array("\\Drafts", $folder_info["attributes"]) && $folder_info["special"]) {
                        $under_drafts = true;
                        break;
                    }
                }

                if ($param['id'] === 'listcontrols') {
                    //<a href="#select" class="select disabled" data-popup="listselect-menu" data-toggle-button="list-toggle-button" title="<roundcube:label name="select" />"><span class="inner"><roundcube:label name="select" /></span></a>
                    $param['content'] = html::a(["href" => "#sendunsent", "id" => "mailmerge_sendunsent",
                        "class" => "sendunsent send disabled" . ($under_drafts ? "" : " hidden"), "title" => "Send Unsent"],
                        html::span(["class" => "inner"], "Send Unsent"));
                }
                return $param;
            }
            return null;
        });
    }

    public function mailmerge_action(): void
    {

        $input = filter_input_array(INPUT_POST, [
            "_to" => ['filter' => FILTER_CALLBACK, 'options' => [$this, 'filter_callback_split']],
            "_cc" => ['filter' => FILTER_CALLBACK, 'options' => [$this, 'filter_callback_split']],
            "_bcc" => ['filter' => FILTER_CALLBACK, 'options' => [$this, 'filter_callback_split']],
            "_replyto" => ['filter' => FILTER_CALLBACK, 'options' => [$this, 'filter_callback_split']],
            "_followupto" => ['filter' => FILTER_CALLBACK, 'options' => [$this, 'filter_callback_split']],

            "_from" => ['filter' => FILTER_SANITIZE_NUMBER_INT, 'flags' => FILTER_REQUIRE_SCALAR],

            "message" => ['filter' => FILTER_UNSAFE_RAW, 'flags' => FILTER_REQUIRE_SCALAR],
            "_subject" => ['filter' => FILTER_UNSAFE_RAW, 'flags' => FILTER_REQUIRE_SCALAR],

            "_mode" => ['filter' => FILTER_CALLBACK, 'options' => [$this, 'filter_callback_mode']],
            "_compose_id" => ['filter' => FILTER_UNSAFE_RAW, 'flags' => FILTER_REQUIRE_SCALAR],
            "_mdn" => ['filter' => FILTER_VALIDATE_BOOL, 'flags' => FILTER_REQUIRE_SCALAR],
            "_dsn" => ['filter' => FILTER_VALIDATE_BOOL, 'flags' => FILTER_REQUIRE_SCALAR],
            "_priority" => ['filter' => FILTER_SANITIZE_NUMBER_INT, 'flags' => FILTER_REQUIRE_SCALAR],

            "_separator" => ['filter' => FILTER_CALLBACK, 'options' => [$this, "filter_callback_separator"]],
            "_enclosure" => ['filter' => FILTER_CALLBACK, 'options' => [$this, "filter_callback_enclosure"]],
            "_folder" => ['filter' => FILTER_CALLBACK, 'options' => [$this, "filter_callback_folder"]],
        ], true);
        
        $this->log->debug($input, $_REQUEST, $_FILES);

        assert(count($_FILES) === 1, "File missing");

        $csv_data = [];
        // Read CSV
        if (($handle = fopen($_FILES['csv']['tmp_name'], "r")) !== FALSE) {
            $bom = fread($handle, 3); // Read first 3 bytes
            // Compare with UTF-8 BOM bytes (0xEF, 0xBB, 0xBF)
            if($bom !== "\xEF\xBB\xBF") {
                fseek($handle, 0);
            }

            while (($data = fgetcsv($handle, null, $input["_separator"], $input["_enclosure"])) !== FALSE) {
                $csv_data[] = $data;
            }
            fclose($handle);
        }
        $csv_head = $csv_data[0];
        $csv_body = array_slice($csv_data, 1);
        $csv_data = null;

        $dict = array_map(function ($line) use ($csv_head) {
            return array_combine($csv_head, $line);
        }, $csv_body);

        @set_time_limit(360);

        $ctr = 0;

        foreach ($dict as &$line) {
            $mime = new Mail_mime(["html_charset" => "UTF-8", "text_charset" => "UTF-8", "head_charset" => "UTF-8", "text_encoding" => "7bit"]);

            $identity = $this->rc->user->get_identity($input["_from"]);

            // $mime->headers(["X-Mozilla-Status" => "0800", "X-Mozilla-Status2" => "00000000"]);

            $from = $identity['name'] . ' <' . $identity['email'] . '>';
            $mime->setFrom($from);

            $mime->setSubject($this->replace_vars($input["_subject"], $line));

            foreach ($input["_to"] as $recipient) {
                $mime->addTo($this->replace_vars($recipient, $line));
            }

            $mime->headers([
                'Date' => $this->rc->user_date(),
                'User-Agent' => $this->rc->config->get('useragent'),
                'Message-ID' => $this->rc->gen_message_id($input["_from"]),
            ]);

            if (!empty($identity['organization'])) {
                $mime->headers(["Organization" => $identity['organization']]);
            }

            if (count($input["_cc"]) > 0) {
                foreach ($input["_cc"] as $recipient) {
                    $mime->addCc($this->replace_vars($recipient, $line));
                }
            }

            if (count($input["_bcc"]) > 0) {
                foreach ($input["_bcc"] as $recipient) {
                    $mime->addBcc($this->replace_vars($recipient, $line));
                }
            }

            if (count($input["_replyto"]) > 0) {
                $rto = array_map(function ($rr) use ($line) {
                    $this->replace_vars($rr, $line);
                }, $input["_replyto"]);

                $mime->headers([
                    "Reply-To" => $rto,
                    "Mail-Reply-To" => $rto
                ]);
            }

            if (count($input["_followupto"]) > 0) {
                $mime->headers(["Mail-Followup-To" => array_map(function ($rr) use ($line) {
                    $this->replace_vars($rr, $line);
                }, $input["_followupto"])]);
            }

            $this->log->debug("begin message");
            $message = $this->replace_vars($input["message"], $line);
            $this->log->debug("end message");

            if ($input["_mode"] == self::MODE_HTML) {
                $mime->setHTMLBody($message);
                $mime->setTXTBody($this->rc->html2text($message));
            } else {
                $mime->setTXTBody($message);
            }

            if ($input["_mdn"]) {
                $mime->headers(["Disposition-Notification-To" => $from]);
            }

            $a_priorities = [1 => 'highest', 2 => 'high', 4 => 'low', 5 => 'lowest'];

            if (!empty($a_priorities[$input["_priority"]])) {
                $mime->headers(['X-Priority' => sprintf('%d (%s)', $input["_priority"], ucfirst($a_priorities[$input["_priority"]]))]);
            }

            // region Attachment parsing from core
            $COMPOSE =& $_SESSION['compose_data_' . $input["_compose_id"]];
            if (!isset($COMPOSE['attachments'])) {
                $COMPOSE['attachments'] = [];
            }

            $folding = (int)$this->rc->config->get('mime_param_folding');
            foreach ($COMPOSE['attachments'] as $attachment) {
                // This hook retrieves the attachment contents from the file storage backend
                $attachment = $this->rc->plugins->exec_hook('attachment_get', $attachment);
                $is_inline = false;
                $dispurl = null;
                $is_file = !empty($attachment['path']);
                $file = !empty($attachment['path']) ? $attachment['path'] : ($attachment['data'] ?? '');

                if ($input["mode"] == "html") {
                    $dispurl = '/[\'"]\S+display-attachment\S+file=rcmfile' . preg_quote($attachment['id']) . '[\'"]/';
                    $message_body = $mime->getHTMLBody();
                    $is_inline = preg_match($dispurl, $message_body);
                }

                $ctype = $attachment['mimetype'] ?? '';
                $ctype = str_replace('image/pjpeg', 'image/jpeg', $ctype); // #1484914

                // inline image
                if ($is_inline) {
                    // Mail_Mime does not support many inline attachments with the same name (#1489406)
                    // we'll generate cid: urls here to workaround this
                    $cid = preg_replace('/[^0-9a-zA-Z]/', '', uniqid(time(), true));
                    if (preg_match('#(@[0-9a-zA-Z\-\.]+)#', $from, $matches)) {
                        $cid .= $matches[1];
                    } else {
                        $cid .= '@localhost';
                    }

                    if ($dispurl && !empty($message_body)) {
                        $message_body = preg_replace($dispurl, '"cid:' . $cid . '"', $message_body);

                        rcube_utils::preg_error([
                            'message' => 'Could not replace an image reference!',
                        ], true);

                        $mime->setHTMLBody($message_body);
                    }

                    $mime->addHTMLImage($file, $ctype, $attachment['name'], $is_file, $cid);
                } else {
                    $mime->addAttachment($file,
                        $ctype,
                        $attachment['name'],
                        $is_file,
                        $ctype == 'message/rfc822' ? '8bit' : 'base64',
                        'attachment',
                        $attachment['charset'] ?? null,
                        '', '',
                        $folding ? 'quoted-printable' : null,
                        $folding == 2 ? 'quoted-printable' : null,
                        '', RCUBE_CHARSET
                    );
                }
            }
            // endregion

            $draft_info = [];

            // Note: We ignore <UID>.<PART> forwards/replies here
            if (
                !empty($COMPOSE['reply_uid'])
                && ($uid = $COMPOSE['reply_uid'])
                && !preg_match('/^\d+\.[0-9.]+$/', $uid)
            ) {
                $draft_info['type']   = 'reply';
                $draft_info['uid']    = $uid;
                $draft_info['folder'] = $COMPOSE['mailbox'];
            }
            else if (
                !empty($COMPOSE['forward_uid'])
                && ($uid = rcube_imap_generic::compressMessageSet($COMPOSE['forward_uid']))
                && !preg_match('/^\d+[0-9.]+$/', $uid)
            ) {
                $draft_info['type']   = 'forward';
                $draft_info['uid']    = $uid;
                $draft_info['folder'] = $COMPOSE['mailbox'];
            }

            if ($input["_dsn"]) {
                $draft_info['dsn'] = 'on';
            }

            if (!empty($draft_info)) {
                $mime->headers(['X-Draft-Info' => rcmail_sendmail::draftinfo_encode($draft_info)]);
            }

            $msg_str = $mime->getMessage();
            if($this->rc->storage->save_message($input["_folder"], $msg_str)) {
                $ctr++;
            }
        }

        $this->rc->output->show_message("successfully saved $ctr messages to {$input["_folder"]}", "confirmation");
    }

    public function get_folders(): void
    {
        $this->rc->output->command("plugin.mailmerge.folders", [
            'folders' => $this->rc->storage->list_folders(),
            'special_folders' => $this->rc->storage->get_special_folders()
        ]);
    }

    public function send_unsent(array|null $param = null): void
    {
        $mbox = rcube_utils::get_input_string('_mbox', rcube_utils::INPUT_POST);
        $search = rcube_utils::get_input_string('_search', rcube_utils::INPUT_POST);

        $this->log->debug(json_encode([$mbox, $search]));
        $this->log->debug(json_encode($this->rc->storage->get_search_set()));

        $messages = $this->rc->storage->list_messages($mbox);
        $this->log->debug(json_encode($messages));

        $success = 0;
        $fail = 0;

        @set_time_limit(360);

        $this->register_handler("message_send_error", function ($param) {
            $this->log->error("message_send_error: " . json_encode($param));
        });

        foreach ($messages as $m) {
            $MESSAGE = new rcube_message($m->uid, $m->folder);

            $COMPOSE = [];

            if ($draft_info = $MESSAGE->headers->get('x-draft-info')) {
                // get reply_uid/forward_uid to flag the original message when sending
                $info = rcmail_sendmail::draftinfo_decode($draft_info);

                if (!empty($info['type'])) {
                    if ($info['type'] == 'reply') {
                        $COMPOSE['reply_uid'] = $info['uid'];
                    }
                    else if ($info['type'] == 'forward') {
                        $COMPOSE['forward_uid'] = $info['uid'];
                    }
                }

                if (!empty($info['dsn']) && $info['dsn'] === 'on') {
                    $options['dsn_enabled'] = true;
                }

                $COMPOSE['mailbox'] = $info['folder'] ?? null;
            }

            $this->log->debug("processing message $MESSAGE->uid {$MESSAGE->headers->messageID} to {$MESSAGE->headers->to}");

            $SENDMAIL = new rcmail_sendmail($COMPOSE, ["charset" => $MESSAGE->headers->charset ?? RCUBE_CHARSET]);

            $to = $SENDMAIL->email_input_format($MESSAGE->headers->to);
            $ua = $this->rc->config->get('useragent');

            $SENDMAIL->options['dsn_enabled'] = false; //TODO: get from draft info
            $SENDMAIL->options['from'] = $MESSAGE->sender["mailto"];
            $SENDMAIL->options['mailto'] = empty($to) ? "undisclosed-recipients:;" : $to;

            $headers = [
                'Received' => $SENDMAIL->header_received(),
                'Date' => $this->rc->user_date(),
                'From' => $MESSAGE->headers->from,
                'To' => empty($to) ? "undisclosed-recipients:;" : $to,
                'Cc' => $MESSAGE->headers->cc,
                'Bcc' => $MESSAGE->headers->bcc,
                'Subject' => $MESSAGE->subject,
                'Reply-To' => $SENDMAIL->email_input_format($MESSAGE->headers->replyto),
                'Mail-Reply-To' => $SENDMAIL->email_input_format($MESSAGE->headers->replyto),
                'Mail-Followup-To' => $SENDMAIL->email_input_format($MESSAGE->headers->followupto ?? null),
                'In-Reply-To' => $MESSAGE->headers->in_reply_to,
                'References' => $MESSAGE->headers->references,
                'User-Agent' => empty($ua) ?: $ua . "+mailmerge",
                'Message-ID' => $MESSAGE->headers->messageID,
                'X-Sender' => $MESSAGE->sender["mailto"],
                'Disposition-Notification-To' => $MESSAGE->headers->mdn_to,
                'X-Priority' => $MESSAGE->headers->priority,
                'Organization' => $MESSAGE->headers->organization ?? null
            ];

            // remove empty headers
            $headers = array_filter($headers);

            $isHtml = $MESSAGE->has_html_part(false, $part);

            $mime_message = $SENDMAIL->create_message($headers,
                $isHtml ? $MESSAGE->get_part_body($part->mime_id) : $MESSAGE->first_text_part(),
                $isHtml, $MESSAGE->attachments);

//            $this->log->debug($mime_message->getMessage());

            if ($SENDMAIL->deliver_message($mime_message, false)) {
                $this->log->debug("delivered message");
                $this->rc->storage->delete_message($MESSAGE->uid, $MESSAGE->folder);
                $SENDMAIL->save_message($mime_message);
                $success++;
            } else {
                $fail++;
            }
            unset($SENDMAIL, $MESSAGE, $part);
        }

        $this->rc->output->show_message("sent $success messages. $fail failed", $fail === 0 ? "confirmation" : "notice");

//        $this->rc->storage->se
    }

    private function replace_vars(string|null $str, array $dict): string|null
    {
        if (is_null($str)) {
            return null;
        }

        $this->log->debug("call with: " . str_replace("\n", " ", $str));

        assert(is_string($str));

        $open_tags = 0;
        $opened_at = false;
        for ($ptr = 0; $ptr < strlen($str); $ptr++) {
            $this->log->trace($ptr . " " . substr($str, $ptr, 2));
            if (substr($str, $ptr, 2) == '{{') {
                // a new tag is opened
                if ($open_tags == 0) {
                    $opened_at = $ptr;
                }
                $this->log->trace("open tags " . $open_tags);
                $open_tags += 1;
                $this->log->trace("opening tag " . $open_tags);
                $ptr += 1; // skip next char
            }
            if (substr($str, $ptr, 2) == '}}') {
                $ptr += 1; // set ourselves one further to actual tag close
                if ($open_tags > 0) {
                    $this->log->trace("closing tag " . $open_tags);
                    $open_tags -= 1;
                    $this->log->trace("open tags " . $open_tags);
                    if ($open_tags === 0) {
                        // highest hierarchy tag was closed
                        // extract tag without surrounding {{ }}
                        $tag = substr($str, $opened_at + 2, $ptr - $opened_at - 3);
                        $this->log->debug("tag " . $tag);
                        $taglen = strlen($tag) + 4;
                        // parse the tag content that may recursively calls back to here again
                        $replacement = $this->parse_tag($tag, $dict);
                        $this->log->debug("replacement " . $replacement);
                        // extract string parts before and after the current tag
                        $before_tag = substr($str, 0, $opened_at);
                        $after_tag = substr($str, $ptr + 1);
                        // built the new string
                        $str = $before_tag . $replacement . $after_tag;
                        // adjust pointer by length difference of the original tag and its replacement
                        $ptr -= $taglen - strlen($replacement);
                    }
                } // else we have dangling }}-s leave them as is
            }
        }
        return $str;
    }

    private function parse_tag(string $var, array $dict): ?string
    {
        $var = $this->replace_vars($var, $dict);

        if (str_contains($var, "|")) {
            $tokens = explode("|", $var);
            if (count($tokens) >= 5 && in_array($tokens[1], ['*', '^', '$', '==', '>', '>=', '<', '<='])) {
                // Complex comparators
                $val = array_key_exists($tokens[0], $dict) ? $dict[$tokens[0]] : "";
                switch ($tokens[1]) {
                    case '*':
                        // {{name|*|if|then|else}} (includes)
                        // If the value of the field name includes if, then the variable will be replaced by then, else by else.
                        return str_contains($val, $tokens[2]) ? $tokens[3] : $tokens[4];
                    case '^':
                        // {{name|^|if|then|else}} (starts with)
                        // If the value of the field name starts with if, then the variable will be replaced by then, else by else.
                        return str_starts_with($val, $tokens[2]) ? $tokens[3] : $tokens[4];
                    case '$':
                        // {{name|$|if|then|else}} (ends with)
                        // If the value of the field name ends with if, then the variable will be replaced by then, else by else.
                        return str_ends_with($val, $tokens[2]) ? $tokens[3] : $tokens[4];
                    case '==':
                        // {{name|==|if|then|else}} (equal to) (number)
                        // If the value of the field name is equal to if, then the variable will be replaced by then, else by else.
                        return $val == $tokens[2] ? $tokens[3] : $tokens[4];
                    case '>':
                        // {{name|>|if|then|else}} (greater than) (number)
                        // If the value of the field name is greater than if, then the variable will be replaced by then, else by else.
                        return $val > $tokens[2] ? $tokens[3] : $tokens[4];
                    case '>=':
                        // {{name|>=|if|then|else}} (greater than or equal to) (number)
                        // If the value of the field name is greater than or equal to if, then the variable will be replaced by then, else by else.
                        return $val >= $tokens[2] ? $tokens[3] : $tokens[4];
                    case '<':
                        // {{name|<|if|then|else}} (less than) (number)
                        // If the value of the field name is less than if, then the variable will be replaced by then, else by else.
                        return $val < $tokens[2] ? $tokens[3] : $tokens[4];
                    case '<=':
                        // {{name|<=|if|then|else}} (less than or equal to) (number)
                        // If the value of the field name is less than or equal to if, then the variable will be replaced by then, else by else.
                        return $val <= $tokens[2] ? $tokens[3] : $tokens[4];
                    default:
                        // Invalid operation
                        return $var;
                }
            } elseif (count($tokens) >= 3) {
                // {{name|if|then|else}}
                // {{name|if|then}}
                if (array_key_exists($tokens[0], $dict) && $dict[$tokens[0]] === $tokens[1]) {
                    return $tokens[2];
                } else {
                    return count($tokens) == 3 ? "" : $tokens[3];
                }
            } else {
                // Malformed? Return original Tag
                return $var;
            }
        } else {
            if (array_key_exists($var, $dict)) {
                return $dict[$var];
            } else {
                // Column doesn't exit. treat as empty
                return "";
            }
        }
    }

    private function filter_callback_split(string $value): array
    {
        return array_filter(explode(",", $value));
    }

    private function filter_callback_mode(string $value): string
    {
        return strtolower($value) == "html" ? self::MODE_HTML : self::MODE_PLAIN;
    }

    private function filter_callback_separator(string $value): string
    {
        if (strtolower($value) == "tab") {
            return "\t";
        }
        return in_array($value, [",", ";", "|"]) ? $value : ",";
    }

    private function filter_callback_enclosure(string $value): ?string
    {
        return in_array($value, ["\"", "'"]) ? $value : "\"";
    }

    private function filter_callback_folder(string $folder): ?string
    {
        $folders = $this->rc->storage->list_folders();
        $special_folders = $this->rc->storage->get_special_folders();
        $drafts = array_key_exists("drafts", $special_folders) ? $special_folders["drafts"] : "Drafts";

        return in_array($folder, $folders) ? $folder : $drafts;
    }
}