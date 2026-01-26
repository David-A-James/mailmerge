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

        $this->add_hook("ready", function ($param) {
            $this->log->debug('ready', $param);
            $prefs = $this->rc->user->get_prefs();
            if ($param['task'] == 'mail' && $param['action'] === 'compose' && $prefs[__("enabled")]) {

                $header = \html::div('row', \html::div('col-12', \html::span("font-weight-bold", "Mail merge options")));

                $sselect = new html_select(["id" => "mailmergesep"]);
                $sselect->add([", (Comma)", "; (Semicolon)", "| (Pipe)", "Tab"], [",", ";", "|", "tab"]);
                $separator = \html::div("form-group row",
                    \html::label(['for' => 'mailmergesep', 'class' => 'col-form-label col-6'], rcube::Q("Separator")) .
                    \html::div('col-6', $sselect->show(["id" => "mailmergesep", "class" => "custom-select form-control pretty-select"])));

                $eselect = new html_select(["id" => "mailmergeencl"]);
                $eselect->add(["\" (Doube Quotes)", "' (Single Quote)"], ["\"", "'"]);

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

            $msg_str = $mime->getMessage();
            $this->rc->storage->save_message($input["_folder"], $msg_str);
        }
    }

    public function get_folders(): void
    {
        $this->rc->output->command("plugin.mailmerge.folders", [
            'folders' => $this->rc->storage->list_folders(),
            'special_folders' => $this->rc->storage->get_special_folders()
        ]);
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