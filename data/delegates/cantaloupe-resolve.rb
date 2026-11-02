# Cantaloupe delegate script for Omeka S module Image Server.
#
# Case B: resolve any IIIF identifier to an original file, including a clean or
# "ark" identifier that is NOT derivable from the filename. The identifier
# resolution (CleanUrl, value lookup, storage id…) stays in Omeka/PHP and is
# reused as-is through the endpoint "/iiif-resolve"; this delegate only calls it
# and caches the result, so the file extension and the internal storage name
# stay hidden from the public IIIF url.
#
# Images only: audio and video are served by Iiif Server, not Cantaloupe, so
# they never reach this delegate (and the endpoint resolves images only).
#
# Install:
#   1. Copy to /etc/cantaloupe/delegates.rb (or mount it in the container).
#   2. In cantaloupe.properties:
#        delegate_script.enabled          = true
#        delegate_script.pathname         = /etc/cantaloupe/delegates.rb
#        source.static                    = FilesystemSource
#        FilesystemSource.lookup_strategy = ScriptLookupStrategy
#   3. Set OMEKA_HOST / OMEKA_PORT (Omeka, reachable from the container) and
#      ORIGINAL_DIR (originals directory as mounted in the container).
#   4. Add the Cantaloupe container ip to the Image Server setting
#      "imageserver_external_resolver_ips" so the endpoint answers.
#   5. Restart Cantaloupe.
#
# Module Access: set OMEKA_ACCESS=1 to ask Omeka, through the endpoint
# "/access/authorize", whether the client may see the media. Add the Cantaloupe
# container ip to the Access setting "access_ip_proxy_trusted", so the client ip
# sent in X-Forwarded-For is used. The request is denied when Omeka does not
# answer in time (fail-closed). Without OMEKA_ACCESS, every request is allowed.

require 'net/http'
require 'uri'

class CustomDelegate

  attr_accessor :context

  # Omeka, reachable from inside the Cantaloupe container, and its public host
  # name when the virtual host requires it.
  OMEKA_HOST = ENV['OMEKA_HOST'] || 'omeka.example.org'
  OMEKA_PORT = (ENV['OMEKA_PORT'] || 80).to_i
  OMEKA_PUBLIC_HOST = ENV['OMEKA_PUBLIC_HOST']

  # Originals directory, as mounted inside the Cantaloupe container.
  ORIGINAL_DIR = ENV['OMEKA_ORIGINAL_DIR'] || '/files/original'

  # Identifier resolver endpoint (restricted to trusted ips on the Omeka side).
  RESOLVE_PATH = '/iiif-resolve'

  # The identifier to file mapping is immutable, so cache aggressively.
  CACHE_TTL = 3600
  CACHE_MAX = 5000

  @@cache = {}
  @@mutex = Mutex.new

  # Module Access: ask Omeka whether the client may see the media.
  ACCESS_CHECK = %w[1 true yes].include?(ENV['OMEKA_ACCESS'].to_s.downcase)
  ACCESS_TTL = 120
  ACCESS_MAX = 2000

  @@access = {}
  @@access_mutex = Mutex.new

  def source(options = {})
    'FilesystemSource'
  end

  def filesystemsource_pathname(options = {})
    filename = resolve_filename
    filename ? File.join(ORIGINAL_DIR, filename) : nil
  end

  # The storage filename of the identifier, resolved by Omeka and cached.
  def resolve_filename
    identifier = context['identifier'].to_s
    return nil if identifier.empty?

    now = Time.now.to_i
    @@mutex.synchronize do
      hit = @@cache[identifier]
      return hit[:filename] if hit && hit[:expires] > now
    end

    filename = fetch_filename(identifier)
    # A failure is not cached, so a slow Omeka does not hide the file for the
    # whole ttl.
    return nil if filename.nil?

    @@mutex.synchronize do
      @@cache[identifier] = { filename: filename, expires: now + CACHE_TTL }
      if @@cache.size > CACHE_MAX
        @@cache.delete_if { |_, v| v[:expires] < now }
      end
    end

    filename
  end

  # Ask Omeka for the internal storage filename of an identifier. Returns nil on
  # any error so Cantaloupe answers 404.
  def fetch_filename(identifier)
    uri = URI::HTTP.build(
      host: OMEKA_HOST,
      port: OMEKA_PORT,
      path: RESOLVE_PATH,
      query: URI.encode_www_form(identifier: identifier)
    )
    req = Net::HTTP::Get.new(uri)
    req['Host'] = OMEKA_PUBLIC_HOST if OMEKA_PUBLIC_HOST
    res = Net::HTTP.start(uri.host, uri.port, open_timeout: 2, read_timeout: 2) do |http|
      http.request(req)
    end
    return nil unless res.is_a?(Net::HTTPSuccess)

    body = res.body.to_s.strip
    # A storage filename is "<hash>.<ext>": reject empty, traversal and slash
    # (slash-bearing storage ids from module ArchiveRepertory need adaptation).
    return nil if body.empty? || body.include?('/') || body.include?('..')

    body
  rescue StandardError
    nil
  end

  def pre_authorize(options = {})
    return true unless ACCESS_CHECK

    filename = access_filename
    return false if filename.nil?

    key = "#{filename}|#{context['client_ip']}"
    now = Time.now.to_i
    @@access_mutex.synchronize do
      hit = @@access[key]
      return hit[:allowed] if hit && hit[:expires] > now
    end

    allowed = fetch_decision(filename)
    # Fail-closed, without cache, so a slow Omeka does not hide free images for
    # the whole ttl.
    return false if allowed.nil?

    @@access_mutex.synchronize do
      @@access[key] = { allowed: allowed, expires: now + ACCESS_TTL }
      if @@access.size > ACCESS_MAX
        @@access.delete_if { |_, v| v[:expires] < now }
      end
    end

    allowed
  end

  def authorize(options = {})
    pre_authorize(options)
  end

  # Ask the module Access. Returns nil when Omeka is unreachable or too slow.
  def fetch_decision(filename)
    uri = URI::HTTP.build(
      host: OMEKA_HOST,
      port: OMEKA_PORT,
      path: '/access/authorize',
      query: URI.encode_www_form(filename: filename)
    )
    req = Net::HTTP::Get.new(uri)
    req['Host'] = OMEKA_PUBLIC_HOST if OMEKA_PUBLIC_HOST
    req['X-Forwarded-For'] = context['client_ip'] if context['client_ip']
    cookies = context['cookies']
    if cookies && !cookies.empty?
      req['Cookie'] = cookies.map { |k, v| "#{k}=#{v}" }.join('; ')
    end
    res = Net::HTTP.start(uri.host, uri.port, open_timeout: 2, read_timeout: 3, max_retries: 0) do |http|
      http.request(req)
    end
    # Only a 200 allows: Access answers 404 for a reserved media too.
    res.code.to_i == 200
  rescue StandardError
    nil
  end

  # The identifier is resolved first, since Access checks a storage filename.
  def access_filename
    resolve_filename
  end

  # Stubs required by the Cantaloupe delegate interface.
  def deserialize_meta_identifier(meta_identifier); {}; end
  def serialize_meta_identifier(components); ''; end
  def extra_iiif2_information_response_keys(options = {}); {}; end
  def extra_iiif3_information_response_keys(options = {}); {}; end
  def azurestoragesource_blob_key(options = {}); nil; end
  def httpsource_resource_info(options = {}); nil; end
  def jdbcsource_database_identifier(options = {}); nil; end
  def jdbcsource_last_modified(options = {}); nil; end
  def jdbcsource_media_type(options = {}); nil; end
  def overlay(options = {}); nil; end
  def redactions(options = {}); []; end
  def metadata(options = {}); nil; end

end
