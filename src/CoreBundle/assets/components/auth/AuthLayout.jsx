import { Col, Row, Typography } from 'antd';
import React from 'react';

/**
 * Layout of the public pages around the login: the illustration on the left (above on small screens), the content
 * on the right.
 */
export default function AuthLayout({ imageUrl = '', children }) {
    return <div style={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '24px' }}>
        <div style={{ maxWidth: 1100, width: '100%' }}>
            <Row gutter={[48, 32]} align="middle" justify="center">
                {/* Visual / Illustration column */}
                {imageUrl && (
                    <Col xs={24} md={12} lg={12} style={{ textAlign: 'center' }}>
                        <img
                            src={imageUrl}
                            alt="Login visual"
                            style={{ width: '100%', maxWidth: 450, height: 'auto', display: 'inline-block' }}
                        />
                    </Col>
                )}

                {/* Content column */}
                <Col xs={24} md={12} lg={10}>
                    <div style={{ maxWidth: 400, margin: '0 auto' }}>
                        {children}
                    </div>
                </Col>
            </Row>
        </div>
    </div>;
}

/**
 * Error messages of a field, under it.
 *
 * @param {{ errors?: string[] }} props
 */
export function FieldErrors({ errors = [] }) {
    if (errors.length === 0) {
        return null;
    }

    return <div style={{ marginTop: 4 }}>
        {errors.map((error, i) => <Typography.Text key={i} type="danger" style={{ display: 'block' }}>{error}</Typography.Text>)}
    </div>;
}

/**
 * Hidden CSRF field of a Symfony form, when the form has one.
 *
 * @param {{ csrf: { name: string, value: string } | null }} props
 */
export function CsrfInput({ csrf }) {
    return csrf ? <input type="hidden" name={csrf.name} value={csrf.value}/> : null;
}
