import { DeleteOutlined, UploadOutlined } from "@ant-design/icons";
import { useProfileApi } from "@CoreBundle/components/profile/ProfileApiContext";
import { useCurrentUser } from "@CoreBundle/components/user/CurrentUserContext";
import UserAvatar from "@CoreBundle/components/user/UserAvatar";
import { App, Button, Flex, Popconfirm, Typography, Upload } from "antd";
import React from "react";

// Same limits as the backend (ApiMeController::apiMeAvatarUpload), checked here to fail before uploading
const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
// '5M' in Symfony: 5,000,000 bytes
const MAX_SIZE = 5_000_000;

/**
 * Profile image of the logged-in user: upload (replaces the current one) and remove (back to the initial).
 */
export default function AvatarEditor() {
    const { user, setUser } = useCurrentUser();
    const api = useProfileApi();
    const { message } = App.useApp();
    const [busy, setBusy] = React.useState(false);

    const run = (promiseFn) => {
        setBusy(true);
        // Both requests answer with the updated user: the header and this page read the new image from it
        return promiseFn()
            .then(res => setUser(res.data))
            .catch(() => {})
            .finally(() => setBusy(false));
    };

    const beforeUpload = (file) => {
        // Some systems give no type (e.g. for .webp): the backend checks the content anyway
        if (file.type && !ACCEPTED_TYPES.includes(file.type)) {
            message.error('The image must be a JPEG, PNG or WebP file.');
            return Upload.LIST_IGNORE;
        }
        if (file.size > MAX_SIZE) {
            message.error('The image must be at most 5 MB.');
            return Upload.LIST_IGNORE;
        }
        return true;
    };

    const upload = ({ file, onSuccess, onError }) => run(() => api.withLoadingMessage({
        key: 'avatar',
        loadingContent: 'Uploading the profile image...',
        successContent: 'Profile image updated',
    }).uploadAvatar(file).then(res => {
        onSuccess(res.data);
        return res;
    }, err => {
        onError(err);
        throw err;
    }));

    const remove = () => run(() => api.withLoadingMessage({
        key: 'avatar',
        loadingContent: 'Removing the profile image...',
        successContent: 'Profile image removed',
    }).removeAvatar());

    return <Flex align="center" gap="large" wrap>
        <UserAvatar user={user} size={96}/>
        <Flex vertical gap="small" style={{ flex: 1, minWidth: 200 }}>
            <Flex gap="small" wrap>
                <Upload
                    accept={ACCEPTED_TYPES.join(',')}
                    showUploadList={false}
                    beforeUpload={beforeUpload}
                    customRequest={upload}
                    disabled={busy}
                >
                    <Button icon={<UploadOutlined/>} loading={busy}>
                        {user.avatar_url ? 'Change image' : 'Upload image'}
                    </Button>
                </Upload>
                {user.avatar_url && <Popconfirm
                    title="Remove the profile image?"
                    okText="Remove"
                    okButtonProps={{ danger: true }}
                    onConfirm={remove}
                >
                    <Button danger icon={<DeleteOutlined/>} disabled={busy}>Remove</Button>
                </Popconfirm>}
            </Flex>
            <Typography.Text type="secondary">
                JPEG, PNG or WebP, up to 5 MB, at least 128×128 px. It is cropped to a square from the centre.
            </Typography.Text>
        </Flex>
    </Flex>;
}
