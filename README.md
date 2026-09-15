<h1>Cara Instalasi</h1>

<ol>
  <li>
    <strong>Clone Repositori</strong>
    <pre><code>git clone &lt;https://github.com/Mheszzz/sahabat-sos-backend&gt;
cd &lt;nama folder&gt;</code></pre>
  </li>

  <li>
    <strong>Instal Dependensi PHP</strong>
    <pre><code>composer install</code></pre>
  </li>

  <li>
    <strong>Atur Environment (.env)</strong>
    <p>Salin file <code>.env.example</code> menjadi <code>.env</code> lalu sesuaikan databasenya.</p>
    <pre><code>cp .env.example .env</code></pre>
  </li>

  <li>
    <strong>Generate Key Aplikasi</strong>
    <pre><code>php artisan key:generate</code></pre>
  </li>

  <li>
    <strong>Jalankan Migrasi Database</strong>
    <pre><code>php artisan migrate</code></pre>
  </li>

  <li>
    <strong>Instal Laravel Socialite</strong>
    <pre><code>composer require laravel/socialite</code></pre>
  </li>

  <li>
    <strong>Instal API</strong>
    <pre><code>php artisan install:api</code></pre>
  </li>

  <li>
    <strong>Instal Reverb</strong>
    <pre><code>php artisan install:reverb</code></pre>
  </li>
</ol>
