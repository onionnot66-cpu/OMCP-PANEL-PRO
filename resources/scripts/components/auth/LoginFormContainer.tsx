import React, { forwardRef } from 'react';
import { Form } from 'formik';
import styled from 'styled-components/macro';
import { breakpoint } from '@/theme';
import FlashMessageRender from '@/components/FlashMessageRender';
import tw from 'twin.macro';

type Props = React.DetailedHTMLProps<React.FormHTMLAttributes<HTMLFormElement>, HTMLFormElement> & {
    title?: string;
};

const Container = styled.div`
    ${breakpoint('sm')`
        ${tw`w-4/5 mx-auto`}
    `};

    ${breakpoint('md')`
        ${tw`p-10`}
    `};

    ${breakpoint('lg')`
        ${tw`w-3/5`}
    `};

    ${breakpoint('xl')`
        ${tw`w-full`}
        max-width: 700px;
    `};
`;

const AuthCard = styled.div`
    background: #fff;
    border: 1px solid rgba(129, 96, 222, 0.2);
    border-radius: 18px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.18);
    padding: 28px;
    width: 100%;
    @media (min-width: 768px) {
        display: flex;
        align-items: center;
        gap: 24px;
    }
`;

export default forwardRef<HTMLFormElement, Props>(({ title, ...props }, ref) => (
    <Container>
        {title && <h2 css={tw`text-3xl text-center text-neutral-100 font-medium py-4`}>{title}</h2>}
        <FlashMessageRender css={tw`mb-2 px-1`} />
        <Form {...props} ref={ref}>
            <AuthCard>
                <div css={tw`flex-none select-none mb-6 md:mb-0 self-center`}>
                    <img src={'/assets/svgs/omcp.svg'} alt={'OMCP'} css={tw`block w-48 md:w-64 mx-auto`} />
                </div>
                <div css={tw`flex-1 min-w-0`}>{props.children}</div>
            </AuthCard>
        </Form>
        <p css={tw`text-center text-neutral-400 text-xs mt-4`}>
            OMCP &middot; Based on&nbsp;

            <a
                rel={'noopener nofollow noreferrer'}
                href={'https://pterodactyl.io'}
                target={'_blank'}
                css={tw`no-underline text-neutral-500 hover:text-neutral-300`}
            >
                Pterodactyl Software
            </a>
        </p>
    </Container>
));
