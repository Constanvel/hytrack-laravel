import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Shell } from '@/components/shell';
import { Icon, Notice, RowLink } from '@/components/ui';
import { isActive } from '@/lib/live';
import type { Visit } from '@/types';

interface Message {
  id: number;
  role: 'user' | 'assistant';
  text: string;
}
/** Hya's replies come from Laravel so they can use the patient's live queue data. */
async function askHya(question: string): Promise<string> {
  const token = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
  const response = await fetch('/hya', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-XSRF-TOKEN': token,
    },
    body: JSON.stringify({ question }),
  });
  if (!response.ok) throw new Error(`Hya ${response.status}`);
  return (await response.json()).reply;
}
function summary(visit: Visit) {
  return {
    booked: 'Belum check-in',
    waiting: `${visit.ahead} pasien lagi`,
    serving: 'Giliran Anda',
    laboratory: 'Layanan laboratorium',
    pharmacy: 'Penyiapan obat',
    completed: '',
    no_show: '',
  }[visit.stage];
}
export function Hya() {
  const { visit } = usePage().props;
  const [input, setInput] = useState('');
  const [messages, setMessages] = useState<Message[]>([]);
  const [pending, setPending] = useState(false);
  const last = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (messages.length) last.current?.scrollIntoView({ block: 'nearest', behavior: 'instant' });
  }, [messages, pending]);
  async function ask(question: string) {
    const value = question.trim().slice(0, 500);
    if (!value || pending) return;
    const say = (role: Message['role'], text: string) =>
      setMessages((current) => [...current, { id: current.length, role, text }]);
    say('user', value);
    setInput('');
    setPending(true);
    try {
      say('assistant', await askHya(value));
    } catch {
      say('assistant', 'Hya belum dapat menjawab. Periksa koneksi Anda, lalu coba lagi.');
    } finally {
      setPending(false);
    }
  }
  function submit(event: FormEvent) {
    event.preventDefault();
    ask(input);
  }
  return (
    <Shell title="Hya" subtitle="Asisten digital HyTrack" back="/kunjungan" hya>
      {!messages.length ? (
        <>
          <p className="eyebrow" style={{ color: 'var(--primary)' }}>
            PANDUAN KUNJUNGAN ANDA
          </p>
          <img
            className="hya-illustration"
            src="/assets/hya.png"
            width={400}
            height={300}
            alt="Hya, asisten layanan rumah sakit, melambaikan tangan"
          />
          <section className="intro">
            <h2>Halo, saya Hya.</h2>
            <p>
              Saya bantu Anda mengikuti antrean
              <br />
              dan menemukan layanan berikutnya.
            </p>
          </section>
          {isActive(visit) ? (
            <Notice>
              KUNJUNGAN AKTIF · {visit.ticket}
              <br />
              <strong>
                {summary(visit)} · Poli {visit.clinic}
              </strong>
            </Notice>
          ) : (
            <Notice>
              Belum ada kunjungan aktif. Anda tetap bisa bertanya tentang panduan layanan.
            </Notice>
          )}
          <h3>Apa yang ingin Anda ketahui?</h3>
          <div className="stack">
            {[
              'Kapan giliran saya?',
              'Ke ruangan mana saya harus pergi?',
              'Bagaimana cara check-in?',
            ].map((question) => (
              <button key={question} className="suggestion" onClick={() => ask(question)}>
                {question}
                <Icon name="arrow" />
              </button>
            ))}
          </div>
        </>
      ) : (
        <>
          <Notice>
            {isActive(visit) ? `${visit.ticket} · Poli ${visit.clinic}` : 'Panduan layanan'}
          </Notice>
          <div className="messages" role="log" aria-live="polite" aria-label="Percakapan Hya">
            {messages.map((message) => (
              <div className={`message ${message.role}`} key={message.id}>
                {message.role === 'assistant' && <strong>Hya</strong>}
                <p>{message.text}</p>
              </div>
            ))}
            {pending && (
              <div className="message assistant">
                <strong>Hya</strong>
                <p>Menyiapkan jawaban…</p>
              </div>
            )}
          </div>
          <div ref={last}>
            <RowLink href="/petunjuk-ruangan">Buka petunjuk ruangan</RowLink>
          </div>
          <button
            className="suggestion"
            disabled={pending}
            onClick={() => ask('Bagaimana jika saya terlambat?')}
          >
            Bagaimana jika saya terlambat?
          </button>
          <button className="text-button" onClick={() => setMessages([])}>
            Mulai percakapan baru
          </button>
        </>
      )}
      <Notice tone="warning">
        Untuk keluhan atau pertanyaan medis, silakan hubungi tenaga kesehatan.
      </Notice>
      <div className="chat-composer">
        <form onSubmit={submit}>
          <input
            aria-label="Pertanyaan untuk Hya"
            placeholder="Tulis pertanyaan Anda..."
            maxLength={500}
            value={input}
            onChange={(e) => setInput(e.target.value)}
          />
          <button type="submit" aria-label="Kirim pertanyaan" disabled={!input.trim() || pending}>
            <Icon name="send" />
          </button>
        </form>
        <small>Respons terstruktur, bukan AI. Jangan masukkan data pribadi atau medis.</small>
      </div>
    </Shell>
  );
}
