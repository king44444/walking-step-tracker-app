<?php
/** @var array $users */
/** @var string $csrfToken */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin SMS Console</title>
    <link rel="stylesheet" href="../public/assets/css/app.css">
    <style>
        .sms-container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .user-selector { margin-bottom: 20px; }
        .messages-panel { border: 1px solid #ddd; height: 400px; overflow-y: auto; padding: 10px; margin-bottom: 20px; }
        .message { margin-bottom: 10px; padding: 8px; border-radius: 4px; }
        .message.inbound { background: #e8f5e8; border-left: 4px solid #4caf50; }
        .message.outbound { background: #e3f2fd; border-left: 4px solid #2196f3; }
        .message .timestamp { font-size: 0.8em; color: #666; }
        .message .status { font-size: 0.8em; font-weight: bold; }
        .attachments { margin-top: 5px; }
        .attachment { display: inline-block; margin-right: 10px; }
        .attachment img { max-width: 100px; max-height: 100px; }
        .composer { border: 1px solid #ddd; padding: 15px; margin-bottom: 20px; }
        .composer textarea { width: 100%; min-height: 80px; margin-bottom: 10px; }
        .file-input { margin-bottom: 10px; }
        .uploaded-files { margin-top: 10px; }
        .uploaded-file { display: inline-block; margin-right: 10px; padding: 5px; background: #f5f5f5; border-radius: 3px; }
        .stop-notice { color: #f44336; font-weight: bold; margin-bottom: 10px; }
        .start-btn { background: #4caf50; color: white; border: none; padding: 5px 10px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="sms-container">
        <h1>Admin SMS Console</h1>

        <div class="user-selector">
            <label for="user-select">Select User:</label>
            <select id="user-select">
                <option value="">-- Choose a user --</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= htmlspecialchars($user['id']) ?>">
                        <?= htmlspecialchars($user['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="messages-panel" class="messages-panel" style="display: none;">
            <h3>Message History</h3>
            <div id="messages-list"></div>
        </div>

        <div id="composer" class="composer" style="display: none;">
            <h3>Send Message</h3>
            <div id="stop-notice" class="stop-notice" style="display: none;">
                User has opted out of SMS. <button class="start-btn" onclick="startUser()">Re-enable SMS</button>
            </div>
            <form id="sms-form" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="user_id" id="form-user-id">
                <textarea name="body" placeholder="Type your message..." required></textarea>
                <div class="file-input">
                    <input type="file" id="file-input" multiple accept="image/*,.pdf">
                    <button type="button" onclick="uploadFiles()">Upload Files</button>
                </div>
                <div id="uploaded-files" class="uploaded-files"></div>
                <button type="submit">Send SMS</button>
            </form>
        </div>
    </div>

    <script>
        let currentUserId = null;
        let uploadedFiles = [];

        document.getElementById('user-select').addEventListener('change', function() {
            currentUserId = this.value;
            if (currentUserId) {
                loadMessages();
                showComposer();
            } else {
                hideMessages();
                hideComposer();
            }
        });

        function loadMessages() {
            fetch(`sms.php?action=messages&user_id=${currentUserId}`)
                .then(response => response.json())
                .then(data => {
                    const panel = document.getElementById('messages-panel');
                    const list = document.getElementById('messages-list');
                    list.innerHTML = '';

                    data.messages.forEach(msg => {
                        const msgDiv = document.createElement('div');
                        msgDiv.className = `message ${msg.direction}`;

                        let attachmentsHtml = '';
                        if (msg.attachments && msg.attachments.length > 0) {
                            attachmentsHtml = '<div class="attachments">';
                            msg.attachments.forEach(att => {
                                if (att.mime && att.mime.startsWith('image/')) {
                                    attachmentsHtml += `<div class="attachment"><img src="${att.url}" alt="Attachment"></div>`;
                                } else {
                                    attachmentsHtml += `<div class="attachment"><a href="${att.url}" target="_blank">📎 ${att.mime || 'File'}</a></div>`;
                                }
                            });
                            attachmentsHtml += '</div>';
                        }

                        msgDiv.innerHTML = `
                            <div class="timestamp">${msg.timestamp}</div>
                            <div class="status">${msg.status}${msg.delivery_status ? ` (${msg.delivery_status})` : ''}</div>
                            <div>${msg.body}</div>
                            ${attachmentsHtml}
                        `;
                        list.appendChild(msgDiv);
                    });

                    panel.style.display = 'block';
                });
        }

        function showComposer() {
            document.getElementById('composer').style.display = 'block';
            document.getElementById('form-user-id').value = currentUserId;
            checkStopStatus();
        }

        function hideMessages() {
            document.getElementById('messages-panel').style.display = 'none';
        }

        function hideComposer() {
            document.getElementById('composer').style.display = 'none';
        }

        function checkStopStatus() {
            // This would require an additional API call to check if user is opted out
            // For now, we'll handle this in the send response
        }

        function uploadFiles() {
            const files = document.getElementById('file-input').files;
            if (files.length === 0) return;

            const formData = new FormData();
            formData.append('csrf', '<?= htmlspecialchars($csrfToken) ?>');
            formData.append('user_id', currentUserId);

            for (let file of files) {
                formData.append('files[]', file);
            }

            fetch('sms.php?action=upload', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.uploaded) {
                    data.uploaded.forEach(file => {
                        uploadedFiles.push(file);
                        addUploadedFile(file);
                    });
                }
                if (data.errors && data.errors.length > 0) {
                    alert('Upload errors: ' + data.errors.join(', '));
                }
                document.getElementById('file-input').value = '';
            });
        }

        function addUploadedFile(file) {
            const container = document.getElementById('uploaded-files');
            const div = document.createElement('div');
            div.className = 'uploaded-file';
            div.innerHTML = `
                ${file.name} (${formatBytes(file.size)})
                <button onclick="removeFile(${file.id})">×</button>
            `;
            container.appendChild(div);
        }

        function removeFile(fileId) {
            uploadedFiles = uploadedFiles.filter(f => f.id !== fileId);
            // Remove from UI
            const container = document.getElementById('uploaded-files');
            const files = container.querySelectorAll('.uploaded-file');
            files.forEach(div => {
                if (div.textContent.includes(fileId)) {
                    div.remove();
                }
            });
        }

        function formatBytes(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        document.getElementById('sms-form').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            formData.append('attachments', JSON.stringify(uploadedFiles.map(f => f.id)));

            fetch('sms.php?action=send', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.ok) {
                    alert('Message sent successfully!');
                    this.reset();
                    uploadedFiles = [];
                    document.getElementById('uploaded-files').innerHTML = '';
                    loadMessages(); // Refresh messages
                } else {
                    alert('Send failed: ' + (data.error || 'Unknown error'));
                }
            });
        });

        function startUser() {
            if (!confirm('Are you sure you want to re-enable SMS for this user?')) return;

            const formData = new FormData();
            formData.append('csrf', '<?= htmlspecialchars($csrfToken) ?>');
            formData.append('user_id', currentUserId);

            fetch('sms.php?action=start-user', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.ok) {
                    alert('SMS re-enabled for user');
                    document.getElementById('stop-notice').style.display = 'none';
                } else {
                    alert('Failed to re-enable SMS: ' + (data.error || 'Unknown error'));
                }
            });
        }
    </script>
</body>
</html>
