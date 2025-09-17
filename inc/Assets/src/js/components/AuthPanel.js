import { __ } from '@wordpress/i18n';
import { Button, TextControl, Notice } from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';

const AuthPanel = ({
    isConfigured,
    isAuthenticated,
    authUrl,
    i18n,
    isLoading,
    onAuthStatusChange,
    showEdit,
    setShowEdit,
    savedAppId,
}) => {
    const [appId, setAppId] = useState(savedAppId || '');
    const [appSecret, setAppSecret] = useState('');
    const [notice, setNotice] = useState(null);
    const [saving, setSaving] = useState(false);
    const [loadingAppId, setLoadingAppId] = useState(false);

    // When entering edit mode, pre-fill App ID with savedAppId
    useEffect(() => {
        if (showEdit && savedAppId) {
            setAppId(savedAppId);
        }
        if (showEdit) {
            setAppSecret('');
            setLoadingAppId(true);
            wp.apiFetch({ path: 'pti/v1/auth/status', method: 'GET' })
                .then(data => {
                    setAppId(data.app_id || '');
                    setLoadingAppId(false);
                });
        }
    }, [showEdit, savedAppId]);

    const handleSaveCredentials = () => {
        setSaving(true);
        setNotice(null);
        wp.apiFetch({
            path: 'pti/v1/auth/credentials',
            method: 'POST',
            data: {
                app_id: appId,
                app_secret: appSecret,
            },
        })
            .then((response) => {
                setSaving(false);
                if (response.success) {
                    setNotice({ status: 'success', message: i18n.creds_saved || 'Credentials saved.' });
                    setAppId('');
                    setAppSecret('');
                    if (setShowEdit) setShowEdit(false);
                    if (onAuthStatusChange) onAuthStatusChange();
                } else {
                    setNotice({ status: 'error', message: response.message || i18n.error_saving_creds || 'Error saving credentials.' });
                }
            })
            .catch((error) => {
                setSaving(false);
                setNotice({ status: 'error', message: (error && error.message) || i18n.error_saving_creds || 'Error saving credentials.' });
            });
    };

    // Show credentials form if not configured or editing
    if (!isConfigured || showEdit) {
        return (
            <form onSubmit={e => { e.preventDefault(); handleSaveCredentials(); }}>
                <p>{i18n.not_configured_for_auth || 'App ID and Secret are not configured.'}</p>
                <TextControl
                    label={i18n.app_id_label || 'Instagram App ID'}
                    value={appId}
                    onChange={setAppId}
                    disabled={saving || loadingAppId}
                    __next40pxDefaultSize={true}
                    __nextHasNoMarginBottom={true}
                />
                <TextControl
                    label={i18n.app_secret_label || 'Instagram App Secret'}
                    value={appSecret}
                    onChange={setAppSecret}
                    type="password"
                    disabled={saving || loadingAppId}
                    __next40pxDefaultSize={true}
                    __nextHasNoMarginBottom={true}
                />
                <p style={{ fontSize: 'smaller', color: '#666', marginTop: 0 }}>
                    {__('For security, the App Secret is never shown. Leave blank to keep the current secret.', 'post-to-instagram')}
                </p>
                <Button
                    isPrimary
                    type="submit"
                    disabled={saving || !appId || loadingAppId}
                >
                    {i18n.save_credentials || 'Save Credentials'}
                </Button>
                {isConfigured && setShowEdit && (
                    <Button
                        isSecondary
                        onClick={() => { setShowEdit(false); setNotice(null); }}
                        disabled={saving || loadingAppId}
                        style={{ marginLeft: 8 }}
                    >
                        {__('Cancel', 'post-to-instagram')}
                    </Button>
                )}
                {loadingAppId && <p>{__('Loading App ID...', 'post-to-instagram')}</p>}
                {notice && (
                    <Notice status={notice.status} isDismissible={true} onRemove={() => setNotice(null)}>
                        {notice.message}
                    </Notice>
                )}
            </form>
        );
    }

    // If configured and not editing, show Edit API Credentials button (handled by parent now)
    return null;
};

export default AuthPanel; 