import { useNavigate } from "react-router";
import { Flex, theme as antdTheme, Typography } from "antd";
import React from "react";

export default function ItemCover({ id, hasCover, coverUrl, title, creator }) {
    const { token: { lineWidth, lineType, colorBorderSecondary } } = antdTheme.useToken();
    const navigate = useNavigate();

    if (hasCover) {
        return <img
            onClick={() => navigate(`/books/${id}`)}
            style={{ width: '100%', height: '100%', objectFit: 'cover', aspectRatio: '2 / 3' }}
            src={coverUrl}
            alt="Book cover"
        />;
    }

    return <Flex
        onClick={() => navigate(`/books/${id}`)}
        style={{ display: 'flex', width: '100%', height: '100%', aspectRatio: '2 / 3', padding: '16px 0' }}
        justify='center'
        align='center'
        vertical
    >
        <div style={{
            borderTop: `${lineWidth}px ${lineType} ${colorBorderSecondary}`,
            borderBottom: `${lineWidth}px ${lineType} ${colorBorderSecondary}`,
            width: '100%',
            padding: 8,
        }}>
            <Typography.Paragraph
                style={{ marginBottom: 0 }}
                ellipsis={{ tooltip: true, rows: 6 }}
                strong
            >
                {title}
            </Typography.Paragraph>
        </div>
        <div style={{ width: '100%', marginTop: 4, textAlign: 'right' }}>
            <Typography.Text
                style={{ fontSize: '90%', padding: '0 8px' }}
                ellipsis={{ tooltip: true }}
                type="secondary"
            >
                {creator}
            </Typography.Text>
        </div>
    </Flex>;
}
