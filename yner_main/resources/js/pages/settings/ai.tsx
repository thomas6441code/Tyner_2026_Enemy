import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { route } from 'ziggy-js';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';

const PROVIDER_PRESETS: Record<string, { label: string; baseUrl: string }> = {
    openrouter: { label: 'OpenRouter', baseUrl: 'https://openrouter.ai/api/v1' },
    openai: { label: 'OpenAI', baseUrl: 'https://api.openai.com/v1' },
    custom: { label: 'Custom', baseUrl: '' },
};

interface Props {
    provider: string;
    base_url: string;
    model: string;
    has_api_key: boolean;
    api_key_hint: string | null;
}

export default function AiSettings({ provider, base_url, model, has_api_key, api_key_hint }: Props) {
    const { data, setData, put, processing, errors, recentlySuccessful } = useForm({
        provider,
        base_url,
        model,
        api_key: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('ai-settings.update'), { preserveScroll: true });
    };

    const providerKnown = Object.prototype.hasOwnProperty.call(PROVIDER_PRESETS, data.provider);

    return (
        <AppLayout header={<h2 className="text-lg font-semibold">AI Settings</h2>}>
            <Head title="AI Settings" />

            <Card>
                <CardContent className="p-6">
                    <section>
                        <header>
                            <h2 className="text-base font-semibold">Report Summary LLM</h2>
                            <p className="text-sm text-muted-foreground">
                                Configure the provider, model, and API key used to generate monthly report
                                narratives. Point this at OpenRouter (default) or any OpenAI-compatible
                                endpoint — no redeploy needed to switch models.
                            </p>
                        </header>

                        <form onSubmit={submit} className="mt-4 flex max-w-lg flex-col gap-4">
                            <div>
                                <Label htmlFor="provider">Provider</Label>
                                <Select
                                    value={providerKnown ? data.provider : 'custom'}
                                    onValueChange={(value) => {
                                        setData((prev) => ({
                                            ...prev,
                                            provider: value,
                                            base_url: PROVIDER_PRESETS[value]?.baseUrl || prev.base_url,
                                        }));
                                    }}
                                >
                                    <SelectTrigger id="provider" className="mt-1">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(PROVIDER_PRESETS).map(([value, preset]) => (
                                            <SelectItem key={value} value={value}>
                                                {preset.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.provider} className="mt-2" />
                            </div>

                            <div>
                                <Label htmlFor="base_url">Base URL</Label>
                                <Input
                                    id="base_url"
                                    value={data.base_url}
                                    className="mt-1"
                                    onChange={(e) => setData('base_url', e.target.value)}
                                />
                                <InputError message={errors.base_url} className="mt-2" />
                            </div>

                            <div>
                                <Label htmlFor="model">Model</Label>
                                <Input
                                    id="model"
                                    value={data.model}
                                    className="mt-1"
                                    placeholder="anthropic/claude-sonnet-4.5"
                                    onChange={(e) => setData('model', e.target.value)}
                                />
                                <p className="mt-1 text-xs text-muted-foreground">
                                    e.g. anthropic/claude-sonnet-4.5, openai/gpt-4o, google/gemini-2.5-pro
                                </p>
                                <InputError message={errors.model} className="mt-2" />
                            </div>

                            <div>
                                <Label htmlFor="api_key">API Key</Label>
                                <Input
                                    id="api_key"
                                    type="password"
                                    autoComplete="off"
                                    value={data.api_key}
                                    className="mt-1"
                                    placeholder={
                                        has_api_key
                                            ? `Saved (•••• ${api_key_hint}) — leave blank to keep`
                                            : 'sk-or-...'
                                    }
                                    onChange={(e) => setData('api_key', e.target.value)}
                                />
                                <InputError message={errors.api_key} className="mt-2" />
                            </div>

                            <div className="flex items-center gap-3">
                                <Button type="submit" disabled={processing}>
                                    Save
                                </Button>

                                {recentlySuccessful && <p className="text-sm text-muted-foreground">Saved.</p>}
                            </div>
                        </form>
                    </section>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
