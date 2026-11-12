// Rute pratinjau SEMENTARA komponen fondasi redesain gelombang 2 Fase 1.
// TIDAK di-commit ke repo aplikasi: salin ke `src/app/pratinjau-redesain-v2.tsx`
// pada SALINAN uji saja, lalu buka `/pratinjau-redesain-v2`. Seluruh nama dan
// angka di sini contoh sintetis, bukan data.
import { useState } from 'react';
import { ScrollView, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { AppButton } from '@/components/app-button';
import { ScreenHeader } from '@/components/screen-header';
import { EmptyState, ErrorState, LoadingState } from '@/components/screen-state';
import { ThemedText } from '@/components/themed-text';
import { ActionBar } from '@/components/ui/action-bar';
import { AvatarInisial } from '@/components/ui/avatar-inisial';
import { BarChart } from '@/components/ui/bar-chart';
import { Chip } from '@/components/ui/chip';
import { EntityRow } from '@/components/ui/entity-row';
import { Fab, FAB_RUANG_BAWAH } from '@/components/ui/fab';
import { FilterPills } from '@/components/ui/filter-pills';
import { HeroCard } from '@/components/ui/hero-card';
import { InfoRow } from '@/components/ui/info-row';
import { RowGroup } from '@/components/ui/list-row';
import { MenuTileGrid } from '@/components/ui/menu-tile';
import { ProfileHeader } from '@/components/ui/profile-header';
import { ReceiptCard } from '@/components/ui/receipt-card';
import { SearchBar } from '@/components/ui/search-bar';
import { SegmentTabs } from '@/components/ui/segment-tabs';
import { StatGrid } from '@/components/ui/stat-card';
import { StatusPill } from '@/components/ui/status-pill';
import { SuccessPanel } from '@/components/ui/success-panel';
import { SectionHeader } from '@/components/ui/surface';
import { useTheme } from '@/hooks/use-theme';

export default function PratinjauRedesainV2() {
  const theme = useTheme();
  const insets = useSafeAreaInsets();
  const [cari, setCari] = useState('');
  const [pil, setPil] = useState<'perlu' | 'semua' | 'setuju' | 'tolak'>('perlu');
  const [tab, setTab] = useState<'ringkasan' | 'tagihan' | 'tabungan'>('ringkasan');
  const [saring, setSaring] = useState<'semua' | 'putra' | 'putri'>('semua');
  const [catatan, setCatatan] = useState('belum ada');

  return (
    <View style={{ flex: 1, backgroundColor: theme.background }}>
      <ScrollView contentContainerStyle={[styles.isi, { paddingTop: insets.top + 8, paddingBottom: FAB_RUANG_BAWAH + 24 }]}>
        <ScreenHeader
          variant="stack"
          title="Pratinjau fondasi"
          menu={[
            { label: 'Cetak', icon: 'printer', onPress: () => setCatatan('menu: Cetak') },
            { label: 'Bagikan', icon: 'share', onPress: () => setCatatan('menu: Bagikan') },
          ]}
        />
        <ThemedText testID="catatan">Aksi terakhir: {catatan}</ThemedText>

        <HeroCard overline="SALDO TABUNGAN" value="Rp 300.000" caption="Contoh kartu sorot dengan angka besar" action={{ label: 'Lihat mutasi', icon: 'arrow-right', onPress: () => setCatatan('hero') }} />

        <SectionHeader title="StatGrid" />
        <StatGrid items={[
          { icon: 'clock', value: '4', label: 'Transfer menunggu verifikasi', tone: 'warning' },
          { icon: 'wallet', value: '12', label: 'Tunggakan', tone: 'danger' },
          { icon: 'users', value: '120', label: 'Santri aktif', tone: 'info' },
        ]} />

        <SectionHeader title="SearchBar + lembar penyaring" />
        <SearchBar
          placeholder="Cari nama atau NIS"
          value={cari}
          onChangeText={setCari}
          filterTitle="Saring daftar"
          filterActive={saring !== 'semua'}
          filter={(tutup) => (
            <View style={styles.lembar}>
              {(['semua', 'putra', 'putri'] as const).map((v) => (
                <Chip key={v} label={`Unit ${v}`} selected={saring === v} accessibilityRole="radio" onPress={() => setSaring(v)} />
              ))}
              <AppButton label="Terapkan" onPress={() => { setCatatan(`saring: ${saring}`); tutup(); }} />
            </View>
          )}
        />
        <ThemedText testID="cari">Kata kunci: {cari || '—'}</ThemedText>

        <SectionHeader title="FilterPills" />
        <FilterPills
          accessibilityLabel="Saring status"
          value={pil}
          onChange={setPil}
          options={[
            { value: 'perlu', label: 'Perlu tindakan · 12' },
            { value: 'semua', label: 'Semua' },
            { value: 'setuju', label: 'Disetujui' },
            { value: 'tolak', label: 'Ditolak' },
          ]}
        />
        <ThemedText testID="pil">Pil: {pil}</ThemedText>

        <SectionHeader title="EntityRow + AvatarInisial + StatusPill" />
        <EntityRow avatarName="01 Ahmad Fauzi Rahman" title="Ahmad Fauzi Rahman" subtitle="NIS 2026001 • 1 IBTIDA PA" value="Rp 150.000" pill={<StatusPill label="Menunggu" tone="warning" />} onPress={() => setCatatan('baris: Ahmad')} />
        <EntityRow icon="wallet" tone="info" title="Setoran tunai" subtitle="Kamis, 5 Nov 2026" value="+ Rp 50.000" valueTone="primary" pill={<StatusPill label="Disahkan" tone="success" />} />
        <EntityRow icon="clock" tone="danger" title="Tagihan November" subtitle="Lewat jatuh tempo" value="Rp 300.000" valueTone="danger" pill={<StatusPill label="Tunggakan" tone="danger" />} onPress={() => setCatatan('baris: tagihan')} />
        <View style={styles.baris}>
          <AvatarInisial nama="Siti Aminah" tone="violet" />
          <AvatarInisial nama="Budi" tone="warning" ukuran={56} />
          <StatusPill label="Aktif" tone="success" />
          <StatusPill label="Internal" tone="neutral" icon="lock" />
          <StatusPill label="Rujukan" tone="info" />
        </View>

        <SectionHeader title="ProfileHeader + SegmentTabs + InfoRow" />
        <ProfileHeader
          avatar={<AvatarInisial nama="Ahmad Fauzi Rahman" ukuran={88} />}
          name="Ahmad Fauzi Rahman"
          caption="NIS 2026001 • 1 IBTIDA PA"
          pills={<StatusPill label="Aktif" tone="success" />}
          stats={[{ value: 'Rp 300.000', label: 'Saldo' }, { value: 'Rp 150.000', label: 'Sisa tagihan' }, { value: '1', label: 'Transfer menunggu' }]}
        />
        <SegmentTabs
          accessibilityLabel="Bagian rincian"
          value={tab}
          onChange={setTab}
          tabs={[{ value: 'ringkasan', label: 'Ringkasan' }, { value: 'tagihan', label: 'Tagihan' }, { value: 'tabungan', label: 'Tabungan' }]}
        />
        <ThemedText testID="tab">Tab: {tab}</ThemedText>
        <RowGroup>
          <InfoRow icon="calendar" label="Rentang" value="Kamis, 5 Nov 2026 – Sabtu, 7 Nov 2026" />
          <InfoRow icon="info" label="Alasan" value={'Keperluan keluarga yang cukup panjang\nsampai dua baris'} />
          <InfoRow icon="person" label="Username" value="@contoh" locked last />
        </RowGroup>

        <SectionHeader title="SuccessPanel + ReceiptCard" />
        <SuccessPanel title="Pembayaran tercatat" message="Kuitansi tersimpan di riwayat pembayaran.">
          <AppButton label="Selesai" onPress={() => setCatatan('selesai')} />
        </SuccessPanel>
        <SuccessPanel tone="menunggu" title="Menunggu verifikasi" message="Transfer diperiksa Petugas BSA." />
        <ReceiptCard
          title="Kuitansi pembayaran"
          subtitle="No. KW-2026-000123"
          rows={[
            { label: 'Santri', value: 'Ahmad Fauzi Rahman' },
            { label: 'Tagihan', value: 'November 2026' },
            { label: 'Cara bayar', value: 'Tunai' },
            { label: 'Nominal', value: 'Rp 300.000', emphasis: true },
          ]}
          footer="Cetak/PDF tersedia di website."
        />

        <SectionHeader title="BarChart" />
        <BarChart
          accessibilityLabel="Rekap kehadiran"
          initialKey="hadir"
          data={[
            { key: 'hadir', label: 'Hadir', value: 24 },
            { key: 'izin', label: 'Izin', value: 3 },
            { key: 'sakit', label: 'Sakit', value: 2 },
            { key: 'alpa', label: 'Alpa', value: 0 },
          ]}
        />

        <SectionHeader title="MenuTile, keadaan layar" />
        <MenuTileGrid items={[
          { icon: 'calendar', label: 'Jadwal', onPress: () => {} },
          { icon: 'izin', label: 'Perizinan', tone: 'warning', onPress: () => {} },
          { icon: 'chart', label: 'Laporan', tone: 'info', onPress: () => {} },
          { icon: 'users', label: 'Pembinaan', tone: 'violet', onPress: () => {} },
        ]} />
        <EmptyState title="Belum ada data" message="Contoh keadaan kosong." />
        <LoadingState label="Memuat contoh…" />
        <ErrorState message="Contoh galat jaringan." onRetry={() => setCatatan('coba lagi')} />
      </ScrollView>
      <Fab label="Ajukan izin" accessibilityLabel="Ajukan izin baru" onPress={() => setCatatan('fab')} bottomOffset={96} />
      <ActionBar>
        <AppButton label="Simpan draf" variant="secondary" onPress={() => setCatatan('draf')} style={styles.flex} />
        <AppButton label="Serahkan" onPress={() => setCatatan('serahkan')} style={styles.flex} />
      </ActionBar>
    </View>
  );
}

const styles = StyleSheet.create({
  isi: { paddingHorizontal: 20, gap: 14, width: '100%', maxWidth: 760, alignSelf: 'center' },
  baris: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', gap: 10 },
  lembar: { gap: 10 },
  flex: { flex: 1 },
});
