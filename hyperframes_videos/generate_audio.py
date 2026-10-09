import asyncio
import edge_tts

VOICE = "id-ID-ArdiNeural"

texts = [
    "Overview Vendor Terdaftar! Menampilkan sebaran vendor di seluruh Indonesia berdasarkan skor risiko dan taksonomi.",
    "Analisis Pengeluaran! Memetakan posisi vendor sebagai mitra strategis berdasarkan taksonomi pengeluaran.",
    "Hasil Evaluasi Vendor! Penilaian performa secara kualitatif dan kuantitatif berdasarkan pengiriman barang dan penyelesaian jasa.",
    "Form Evaluasi Digital! Kemudahan bagi pengguna untuk mengisi penilaian performa vendor langsung ke dalam sistem.",
    "SLA Lead Time Tracking! Dashboard transparan untuk memastikan layanan pengadaan yang lebih cepat dan selalu tepat waktu.",
    "Kamus SKU dan Integrasi AI! Membantu pengguna mencari item yang relevan, serta memetakan taksonomi secara otomatis ke tim pengadaan yang tepat.",
    "Pembukaan Seal Penawaran! Menjamin proses tender yang adil dan transparan melalui pembukaan segel penawaran yang aman.",
    "Integrasi Profil Risiko! Menghubungkan RFQ dengan data vendor untuk memitigasi risiko sejak tahap awal tender.",
    "Penawaran Digital! Memudahkan vendor untuk mengisi penawaran komersial langsung melalui sistem VSS.",
    "Tender Committee Seal Opening! Pembukaan segel tender oleh komite untuk meninjau rincian penawaran secara kredibel."
]

async def generate(prefix, rate, pitch):
    for i, text in enumerate(texts):
        print(f"Generating {prefix} VO {i+1}...")
        communicate = edge_tts.Communicate(text, VOICE, rate=rate, pitch=pitch)
        await communicate.save(f"assets/{prefix}_{i+1}.mp3")

async def main():
    print("Generating Heavy Voice (Berat)...")
    await generate("vo_heavy", "+5%", "-15Hz")
    
    print("Generating Current Voice (Enerjik)...")
    await generate("vo_current", "+15%", "+10Hz")
    
    print("All VO generated.")

if __name__ == "__main__":
    asyncio.run(main())
