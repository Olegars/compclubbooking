package space.club0451.client

import android.app.DownloadManager
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.net.Uri
import android.os.Build
import android.os.Environment
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import android.view.View
import android.widget.ProgressBar
import android.widget.TextView
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.FileProvider
import org.json.JSONObject
import java.io.File
import java.net.HttpURLConnection
import java.net.URL
import kotlin.concurrent.thread

class AppUpdate(
    private val activity: AppCompatActivity,
    private val bar: View,
    private val status: TextView,
    private val progress: ProgressBar,
) {
    private val prefs = activity.getSharedPreferences("client_update", Context.MODE_PRIVATE)
    private val dm = activity.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
    private val handler = Handler(Looper.getMainLooper())
    private var polling = false
    private var pendingInstall = false
    private var remoteCode = 0

    private val completeReceiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context?, intent: Intent?) {
            val id = intent?.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1) ?: return
            if (id != prefs.getLong(KEY_DOWNLOAD_ID, -1L)) return
            stopPolling()
            if (isOutdated() && downloadSuccessful(id)) {
                installDownloaded()
            } else {
                markUpToDate()
            }
        }
    }

    fun start() {
        val filter = IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE)
        if (Build.VERSION.SDK_INT >= 33) {
            activity.registerReceiver(completeReceiver, filter, Context.RECEIVER_EXPORTED)
        } else {
            @Suppress("UnspecifiedRegisterReceiverFlag")
            activity.registerReceiver(completeReceiver, filter)
        }
        check()
    }

    fun stop() {
        stopPolling()
        try {
            activity.unregisterReceiver(completeReceiver)
        } catch (_: Exception) {
        }
    }

    fun onResume() {
        if (!pendingInstall) return
        pendingInstall = false
        if (isOutdated()) installDownloaded() else markUpToDate()
    }

    fun check() {
        thread {
            try {
                val conn = (URL("${BuildConfig.CLUB_URL.trimEnd('/')}/app.json").openConnection() as HttpURLConnection)
                conn.connectTimeout = 8000
                conn.readTimeout = 8000
                conn.instanceFollowRedirects = true
                conn.setRequestProperty("Accept", "application/json")
                conn.setRequestProperty("User-Agent", "CompClubClient/${BuildConfig.VERSION_NAME}")
                conn.connect()
                if (conn.responseCode != 200) return@thread
                val body = conn.inputStream.bufferedReader().readText()
                conn.disconnect()
                val json = JSONObject(body)
                val code = json.optInt("version_code", 0)
                val apkUrl = json.optString("apk_url").ifBlank { "${BuildConfig.CLUB_URL.trimEnd('/')}/app.apk" }
                activity.runOnUiThread {
                    remoteCode = code
                    prefs.edit().putInt(KEY_REMOTE_CODE, code).apply()
                    if (code <= BuildConfig.VERSION_CODE) {
                        markUpToDate()
                    } else {
                        download(apkUrl, code)
                    }
                }
            } catch (_: Exception) {
            }
        }
    }

    fun downloadFromSite() {
        check()
    }

    private fun isOutdated(): Boolean {
        val remote = maxOf(remoteCode, prefs.getInt(KEY_REMOTE_CODE, 0))
        return remote > BuildConfig.VERSION_CODE
    }

    private fun markUpToDate() {
        pendingInstall = false
        stopPolling()
        hideBar()
        val existing = prefs.getLong(KEY_DOWNLOAD_ID, -1L)
        if (existing > 0L) {
            try {
                dm.remove(existing)
            } catch (_: Exception) {
            }
        }
        prefs.edit().clear().apply()
        val file = updateFile()
        if (file.exists()) file.delete()
    }

    private fun download(apkUrl: String, code: Int) {
        if (code <= BuildConfig.VERSION_CODE) {
            markUpToDate()
            return
        }
        val existing = prefs.getLong(KEY_DOWNLOAD_ID, -1L)
        if (existing > 0L && isRunning(existing)) {
            showBar("Загрузка обновления…")
            startPolling()
            return
        }

        updateFile().parentFile?.mkdirs()
        if (updateFile().exists()) updateFile().delete()

        val request = DownloadManager.Request(Uri.parse(apkUrl))
            .setTitle("0451")
            .setDescription("Загрузка обновления")
            .setMimeType("application/vnd.android.package-archive")
            .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE)
            .setAllowedOverMetered(true)
            .setAllowedOverRoaming(true)
            .setDestinationInExternalFilesDir(
                activity,
                Environment.DIRECTORY_DOWNLOADS,
                APK_NAME,
            )
        request.addRequestHeader("User-Agent", "CompClubClient/${BuildConfig.VERSION_NAME}")

        val id = dm.enqueue(request)
        prefs.edit()
            .putLong(KEY_DOWNLOAD_ID, id)
            .putInt(KEY_REMOTE_CODE, code)
            .apply()
        showBar("Загрузка обновления…")
        startPolling()
    }

    private fun startPolling() {
        if (polling) return
        polling = true
        handler.post(pollRunnable)
    }

    private fun stopPolling() {
        polling = false
        handler.removeCallbacks(pollRunnable)
    }

    private val pollRunnable = object : Runnable {
        override fun run() {
            if (!polling) return
            val id = prefs.getLong(KEY_DOWNLOAD_ID, -1L)
            if (id <= 0L) return
            val query = DownloadManager.Query().setFilterById(id)
            dm.query(query).use { cursor ->
                if (cursor != null && cursor.moveToFirst()) {
                    val statusIdx = cursor.getColumnIndex(DownloadManager.COLUMN_STATUS)
                    val soFarIdx = cursor.getColumnIndex(DownloadManager.COLUMN_BYTES_DOWNLOADED_SO_FAR)
                    val totalIdx = cursor.getColumnIndex(DownloadManager.COLUMN_TOTAL_SIZE_BYTES)
                    val downloadStatus = cursor.getInt(statusIdx)
                    val soFar = cursor.getLong(soFarIdx)
                    val total = cursor.getLong(totalIdx)
                    if (total > 0L) {
                        val pct = ((soFar * 100) / total).toInt().coerceIn(0, 100)
                        progress.isIndeterminate = false
                        progress.progress = pct
                        this@AppUpdate.status.text = "Загрузка обновления… $pct%"
                    }
                    if (downloadStatus == DownloadManager.STATUS_SUCCESSFUL) {
                        stopPolling()
                        if (isOutdated()) installDownloaded() else markUpToDate()
                        return
                    }
                    if (downloadStatus == DownloadManager.STATUS_FAILED) {
                        stopPolling()
                        showBar("Не удалось скачать обновление")
                        return
                    }
                }
            }
            handler.postDelayed(this, 400)
        }
    }

    private fun isRunning(id: Long): Boolean {
        dm.query(DownloadManager.Query().setFilterById(id)).use { cursor ->
            if (cursor == null || !cursor.moveToFirst()) return false
            val status = cursor.getInt(cursor.getColumnIndex(DownloadManager.COLUMN_STATUS))
            return status == DownloadManager.STATUS_RUNNING || status == DownloadManager.STATUS_PENDING
        }
    }

    private fun downloadSuccessful(id: Long): Boolean {
        dm.query(DownloadManager.Query().setFilterById(id)).use { cursor ->
            if (cursor == null || !cursor.moveToFirst()) return false
            return cursor.getInt(cursor.getColumnIndex(DownloadManager.COLUMN_STATUS)) ==
                DownloadManager.STATUS_SUCCESSFUL
        }
    }

    private fun installDownloaded() {
        if (!isOutdated()) {
            markUpToDate()
            return
        }
        val file = updateFile()
        if (!file.isFile || file.length() < 1024L) {
            showBar("Файл обновления не найден")
            return
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O &&
            !activity.packageManager.canRequestPackageInstalls()
        ) {
            pendingInstall = true
            showBar("Разрешите установку из этого приложения")
            val settings = Intent(
                Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                Uri.parse("package:${activity.packageName}"),
            )
            activity.startActivity(settings)
            return
        }
        showBar("Установка обновления…")
        val uri = FileProvider.getUriForFile(
            activity,
            "${activity.packageName}.fileprovider",
            file,
        )
        val install = Intent(Intent.ACTION_VIEW).apply {
            setDataAndType(uri, "application/vnd.android.package-archive")
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            putExtra(Intent.EXTRA_NOT_UNKNOWN_SOURCE, true)
        }
        val resInfo = activity.packageManager.queryIntentActivities(install, 0)
        for (info in resInfo) {
            activity.grantUriPermission(
                info.activityInfo.packageName,
                uri,
                Intent.FLAG_GRANT_READ_URI_PERMISSION,
            )
        }
        activity.startActivity(install)
    }

    private fun updateFile(): File {
        val dir = activity.getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS)
            ?: File(activity.filesDir, "Download")
        return File(dir, APK_NAME)
    }

    private fun showBar(text: String) {
        bar.visibility = View.VISIBLE
        status.text = text
        if (!polling) {
            progress.isIndeterminate = true
        }
    }

    private fun hideBar() {
        bar.visibility = View.GONE
    }

    companion object {
        private const val APK_NAME = "update.apk"
        private const val KEY_DOWNLOAD_ID = "download_id"
        private const val KEY_REMOTE_CODE = "remote_code"
    }
}
