<style>
    :root {
        color-scheme: dark;
    }

    * {
        box-sizing: border-box;
    }

    body {
        display: grid;
        place-items: center;
        min-height: 100vh;
        margin: 0;
        background: #0b1120;
        color: #e2e8f0;
        font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
        line-height: 1.5;
    }

    main {
        width: min(100% - 2rem, 26rem);
        padding: 2rem;
        border: 1px solid #334155;
        border-radius: 0.75rem;
        background: #111827;
        box-shadow: 0 24px 60px rgb(0 0 0 / 0.35);
    }

    h1 {
        margin: 0 0 1.25rem;
        font-size: 1.5rem;
        line-height: 1.25;
    }

    p {
        margin: 0 0 1rem;
    }

    label {
        display: block;
        margin-bottom: 0.35rem;
        font-weight: 600;
    }

    input[type="email"],
    input[type="password"],
    input[type="text"] {
        width: 100%;
        padding: 0.65rem 0.75rem;
        border: 1px solid #475569;
        border-radius: 0.4rem;
        background: #0b1120;
        color: inherit;
        font: inherit;
    }

    input:focus-visible,
    button:focus-visible,
    a:focus-visible {
        outline: 2px solid #38bdf8;
        outline-offset: 2px;
    }

    .checkbox {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-weight: 400;
    }

    .checkbox input {
        width: auto;
    }

    button {
        width: 100%;
        padding: 0.7rem 1rem;
        border: 0;
        border-radius: 0.4rem;
        background: #38bdf8;
        color: #082f49;
        font: inherit;
        font-weight: 700;
        cursor: pointer;
    }

    .alert {
        margin-bottom: 1rem;
        padding: 0.75rem 1rem;
        border: 1px solid #b91c1c;
        border-radius: 0.4rem;
        background: #450a0a;
        color: #fecaca;
    }

    .status {
        margin-bottom: 1rem;
        padding: 0.75rem 1rem;
        border: 1px solid #15803d;
        border-radius: 0.4rem;
        background: #052e16;
        color: #bbf7d0;
    }

    form + form {
        margin-top: 1.5rem;
    }

    .links {
        margin: 1.25rem 0 0;
        text-align: center;
    }

    a {
        color: #7dd3fc;
    }
</style>
